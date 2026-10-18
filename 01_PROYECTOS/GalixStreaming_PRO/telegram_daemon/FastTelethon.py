import asyncio
import inspect
import logging
import math
from collections import defaultdict
from typing import Optional, List, AsyncGenerator, Union, Awaitable, DefaultDict, BinaryIO

from telethon import utils, TelegramClient
from telethon.crypto import AuthKey
from telethon.network import MTProtoSender
from telethon.tl.alltlobjects import LAYER
from telethon.tl.functions import InvokeWithLayerRequest
from telethon.tl.functions.auth import ExportAuthorizationRequest, ImportAuthorizationRequest
from telethon.tl.functions.upload import GetFileRequest
from telethon.tl.types import (Document, InputDocumentFileLocation,
                               InputPhotoFileLocation, InputPeerPhotoFileLocation,
                               InputFileLocation)

log: logging.Logger = logging.getLogger("telethon")

TypeLocation = Union[Document, InputDocumentFileLocation, InputPeerPhotoFileLocation,
                     InputFileLocation, InputPhotoFileLocation]


class DownloadSender:
    client: TelegramClient
    sender: MTProtoSender
    request: GetFileRequest
    remaining: int
    stride: int

    def __init__(self, client: TelegramClient, sender: MTProtoSender, file: TypeLocation, offset: int, limit: int,
                 stride: int, count: int) -> None:
        self.sender = sender
        self.client = client
        self.request = GetFileRequest(file, offset=offset, limit=limit)
        self.stride = stride
        self.remaining = count

    async def next(self) -> Optional[bytes]:
        if not self.remaining:
            return None
        result = await self.client._call(self.sender, self.request)
        self.remaining -= 1
        self.request.offset += self.stride
        return result.bytes

    def disconnect(self) -> Awaitable[None]:
        return self.sender.disconnect()


class ParallelTransferrer:
    client: TelegramClient
    loop: asyncio.AbstractEventLoop
    dc_id: int
    senders: Optional[List[DownloadSender]]
    auth_key: AuthKey

    def __init__(self, client: TelegramClient, dc_id: Optional[int] = None) -> None:
        self.client = client
        self.loop = client.loop
        self.dc_id = dc_id or self.client.session.dc_id
        self.auth_key = (None if dc_id and self.client.session.dc_id != dc_id
                         else self.client.session.auth_key)
        self.senders = None

    async def _cleanup(self) -> None:
        await asyncio.gather(*[sender.disconnect() for sender in self.senders])
        self.senders = None

    async def _init_download(self, connections: int, file: TypeLocation, part_count: int,
                             part_size: int) -> None:
        minimum, remainder = divmod(part_count, connections)

        def get_part_count() -> int:
            nonlocal remainder
            if remainder > 0:
                remainder -= 1
                return minimum + 1
            return minimum

        self.senders = [
            await self._create_download_sender(file, 0, part_size, connections * part_size,
                                               get_part_count()),
            *await asyncio.gather(
                *[self._create_download_sender(file, i, part_size, connections * part_size,
                                               get_part_count())
                  for i in range(1, connections)])
        ]

    async def _create_download_sender(self, file: TypeLocation, index: int, part_size: int,
                                      stride: int,
                                      part_count: int) -> DownloadSender:
        return DownloadSender(self.client, await self._create_sender(), file, index * part_size, part_size,
                              stride, part_count)

    async def _create_sender(self) -> MTProtoSender:
        dc = await self.client._get_dc(self.dc_id)
        sender = MTProtoSender(self.auth_key, loggers=self.client._log)
        await sender.connect(self.client._connection(dc.ip_address, dc.port, dc.id,
                                                     loggers=self.client._log,
                                                     proxy=self.client._proxy))
        if not self.auth_key:
            log.debug(f"Exporting auth to DC {self.dc_id}")
            auth = await self.client(ExportAuthorizationRequest(self.dc_id))
            self.client._init_request.query = ImportAuthorizationRequest(id=auth.id,
                                                                         bytes=auth.bytes)
            req = InvokeWithLayerRequest(LAYER, self.client._init_request)
            await sender.send(req)
            self.auth_key = sender.auth_key
        return sender

    async def download(self, file: TypeLocation, file_size: int,
                       connection_count: int = 3,
                       part_size_kb: Optional[float] = None) -> AsyncGenerator[bytes, None]:
        part_size = (part_size_kb or utils.get_appropriated_part_size(file_size)) * 1024
        part_count = math.ceil(file_size / part_size)
        log.debug("Starting parallel download: "
                  f"{connection_count} {part_size} {part_count} {file!s}")
        await self._init_download(connection_count, file, part_count, part_size)

        part = 0
        while part < part_count:
            tasks = []
            for sender in self.senders:
                tasks.append(self.loop.create_task(sender.next()))
            for task in tasks:
                data = await task
                if not data:
                    break
                yield data
                part += 1

        log.debug("Parallel download finished, cleaning up connections")
        await self._cleanup()


MAX_CONNECTIONS = 3
parallel_transfer_locks: DefaultDict[int, asyncio.Lock] = defaultdict(lambda: asyncio.Lock())


async def download_file(client: TelegramClient,
                        location: TypeLocation,
                        out: BinaryIO,
                        progress_callback: callable = None
                        ) -> BinaryIO:
    size = location.size
    dc_id, location = utils.get_input_location(location)
    async with parallel_transfer_locks[dc_id]:
        downloader = ParallelTransferrer(client, dc_id)
        downloaded = downloader.download(location, size, connection_count=MAX_CONNECTIONS)
        async for x in downloaded:
            out.write(x)
            if progress_callback:
                r = progress_callback(out.tell(), size)
                if inspect.isawaitable(r):
                    await r
    return out


async def download_range(client: TelegramClient,
                         location: TypeLocation,
                         offset: int,
                         limit: int,
                         out: BinaryIO) -> int:
    """Descarga SOLO los bytes [offset, offset+limit) de un archivo remoto.

    Usa una unica conexion MTProto secuencial (GetFileRequest, hasta 1 MiB por
    request). Escribe lo recibido en `out` desde su posicion actual y devuelve
    el total de bytes escritos. Pensado para SONDEO PARCIAL (cabeza/cola) sin
    bajar el archivo completo.
    """
    dc_id, input_location = utils.get_input_location(location)
    size = getattr(location, 'size', None)
    # Telegram exige offsets alineados a 1 MiB y limit fijo de 1 MiB por request
    # (el server recorta al llegar a EOF). Alinear el offset hacia abajo.
    PART = 1024 * 1024
    offset = max(0, offset - (offset % PART))
    if size is not None and size > 0:
        limit = max(0, min(limit, size - offset))
    end = offset + limit
    try:
        out.seek(offset)
    except Exception:
        pass
    written = 0
    async with parallel_transfer_locks[dc_id]:
        transferrer = ParallelTransferrer(client, dc_id)
        sender = await transferrer._create_sender()
        try:
            position = offset
            while position < end:
                result = await client._call(sender, GetFileRequest(input_location, offset=position, limit=PART))
                data = result.bytes
                if not data:
                    break
                out.write(data)
                written += len(data)
                position += len(data)
                if len(data) < PART:
                    break
        finally:
            await sender.disconnect()
    return written

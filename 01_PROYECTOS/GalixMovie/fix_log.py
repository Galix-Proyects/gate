import re

# Fix Instru_SISTEMA.txt
with open("/data/data/com.termux/files/home/Proyectos/00_SISTEMA/Instru_SISTEMA.txt", "r", errors="replace") as f:
    content = f.read()
# Strip null bytes and garbage after last clean line
clean = content[:content.rfind("\n- Fecha")]
if clean:
    # find last good entry
    pass

# Better approach: read line by line, keep only valid text lines
with open("/data/data/com.termux/files/home/Proyectos/00_SISTEMA/Instru_SISTEMA.txt", "rb") as f:
    raw = f.read()

# Find the last occurrence of the valid text pattern
marker = b"- Instruccion Antigravity: [M147]"
idx = raw.rfind(marker)
if idx > 0:
    # Find the end of this line and truncate there
    end = raw.find(b"\n", idx)
    if end > 0:
        raw = raw[:end+1]

with open("/data/data/com.termux/files/home/Proyectos/00_SISTEMA/Instru_SISTEMA.txt", "wb") as f:
    f.write(raw)

# Same for GalixMovie
with open("/data/data/com.termux/files/home/Proyectos/01_PROYECTOS/GalixMovie/Instru_GalixMovie.txt", "rb") as f:
    raw = f.read()

marker = b"- Instruccion Antigravity: [M147]"
idx = raw.rfind(marker)
if idx > 0:
    end = raw.find(b"\n", idx)
    if end > 0:
        raw = raw[:end+1]

with open("/data/data/com.termux/files/home/Proyectos/01_PROYECTOS/GalixMovie/Instru_GalixMovie.txt", "wb") as f:
    f.write(raw)

print("FILES_CLEANED")

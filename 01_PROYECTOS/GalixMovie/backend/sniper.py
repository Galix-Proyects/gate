#!/usr/bin/env python3
# -*- coding: utf-8 -*-
# ==============================================================================
# 🧊 GALIX MOVIE - SERVER-SIDE SNIPER v1.0 (DHARMA CORE)
# ==============================================================================
# Diseñado específicamente para correr en el servidor Symmetry Box (Termux).
# Utiliza Selenium Headless + Chromium para interceptar enlaces .m3u8 cifrados.
# ==============================================================================

import sys
import time
import json
import logging
import atexit

# Configurar logs minimalistas para no ensuciar la salida stdout (que lee PHP)
logging.basicConfig(level=logging.ERROR)

try:
    import os
    from selenium import webdriver
    from selenium.webdriver.chrome.options import Options
    from selenium.webdriver.chrome.service import Service
    from selenium.webdriver.common.by import By
    from selenium.common.exceptions import TimeoutException
except ImportError:
    print(json.dumps({"status": "error", "message": "Faltan dependencias. Ejecuta: pip install selenium"}))
    sys.exit(1)

def main():
    if len(sys.argv) < 2:
        print(json.dumps({"status": "error", "message": "No URL provided"}))
        sys.exit(1)
        
    target_url = sys.argv[1]
    
    # 🛡️ Configuración Headless Ultra-Optimizado para Termux (Symmetry)
    chrome_options = Options()
    chrome_options.page_load_strategy = 'eager'
    chrome_options.add_argument('--headless=new')
    chrome_options.add_argument('--no-sandbox')
    chrome_options.add_argument('--disable-gpu')
    chrome_options.add_argument('--disable-dev-shm-usage')
    chrome_options.add_argument('--mute-audio')
    # DHARMA FIX: Eliminamos --dns-over-https-templates para evitar net::ERR_NAME_NOT_RESOLVED,
    # ya que el sistema operativo de Termux ahora usa sus propios nameservers (8.8.8.8) directamente en resolv.conf.
    chrome_options.add_argument('--user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36')

    # 🎯 Especificar rutas de Termux (aarch64) para evadir fallos de Selenium Manager
    termux_chrome_path = "/data/data/com.termux/files/usr/bin/chromium-browser"
    termux_driver_path = "/data/data/com.termux/files/usr/bin/chromedriver"
    
    if os.path.exists(termux_chrome_path):
        chrome_options.binary_location = termux_chrome_path

    service = None
    if os.path.exists(termux_driver_path):
        service = Service(executable_path=termux_driver_path)

    driver = None
    
    # 🛡️ Registrar limpieza garantizada a nivel de intérprete
    def cleanup_driver():
        nonlocal driver
        if driver:
            try:
                driver.quit()
            except:
                pass
    atexit.register(cleanup_driver)

    try:
        if service:
            driver = webdriver.Chrome(options=chrome_options, service=service)
        else:
            driver = webdriver.Chrome(options=chrome_options)
        
        # ⏱️ Definir timeout de carga de página para evitar bloqueos por anuncios lentos (10s)
        driver.set_page_load_timeout(10)
        
        # 1. Navegar directamente al Embed sin llamadas CDP previas (evita colapsar el WebSocket de Termux)
        try:
            driver.get(target_url)
        except TimeoutException:
            pass
        
        # 2. Rutina de Travesía Recursiva e Intercepción de iframes
        resolved_url = None
        start_time = time.time()

        def scan_context_for_m3u8():
            # Intentar escanear en el contexto actual (DOM o Performance)
            try:
                resources = driver.execute_script("""
                    return window.performance.getEntriesByType('resource')
                        .map(r => r.name)
                        .filter(u => typeof u === 'string' && (u.indexOf('.m3u8') !== -1 || u.indexOf('.mp4') !== -1));
                """)
                for req_url in resources:
                    if 'proxy.php' not in req_url and 'analytics' not in req_url:
                        return req_url
            except:
                pass

            try:
                html_source = driver.page_source
                import re
                m3u8_matches = re.findall(r'(https?://[^\s"\'>/]+\.m3u8[^\s"\'>]*)', html_source)
                if m3u8_matches:
                    return m3u8_matches[0]
            except:
                pass
            return None

        # Intentar interactuar y buscar en el nivel superior y descender recursivamente
        def traverse_and_intercept(depth=0):
            nonlocal resolved_url
            if depth > 3 or resolved_url:
                return

            # Clicar cuerpo del contexto actual para forzar inicio/reproducción
            try:
                body = driver.find_element(By.TAG_NAME, 'body')
                body.click()
            except:
                pass

            # Escaneo inmediato del contexto actual
            resolved_url = scan_context_for_m3u8()
            if resolved_url:
                return

            # Buscar iframes para descender
            try:
                iframes = driver.find_elements(By.TAG_NAME, 'iframe')
            except:
                iframes = []

            for i in range(len(iframes)):
                if resolved_url:
                    break
                try:
                    # Volver a buscar el elemento para evitar StaleElementReferenceException
                    current_iframes = driver.find_elements(By.TAG_NAME, 'iframe')
                    if i < len(current_iframes):
                        iframe = current_iframes[i]
                        # Intentar leer src para descartar iframes inútiles (como trackers/ads)
                        iframe_src = iframe.get_attribute('src') or ''
                        if 'google' in iframe_src or 'ads' in iframe_src or 'analytics' in iframe_src:
                            continue
                        
                        # Cambiar de contexto
                        driver.switch_to.frame(iframe)
                        traverse_and_intercept(depth + 1)
                        # Regresar al contexto anterior
                        driver.switch_to.parent_frame()
                except:
                    # En caso de error, intentar restablecer contexto superior y continuar
                    try:
                        driver.switch_to.default_content()
                    except:
                        pass

        # Bucle de intercepción adaptativo con límite de 12 segundos
        while time.time() - start_time < 12:
            traverse_and_intercept()
            if resolved_url:
                break
            time.sleep(1.0)

        if resolved_url:
            print(json.dumps({"status": "success", "url": resolved_url}))
        else:
            print(json.dumps({"status": "error", "message": "No HLS stream intercepted"}))
                
    except Exception as e:
        print(json.dumps({"status": "error", "message": str(e)}))
    finally:
        if driver:
            try:
                driver.quit()
            except:
                pass

if __name__ == '__main__':
    main()

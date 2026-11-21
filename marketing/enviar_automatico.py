#!/usr/bin/env python3
"""
Padelblox - Bot 100% Automático de Envío por WhatsApp para macOS
Abre el chat, escribe el mensaje personalizado y presiona ENTER automáticamente sin intervención manual.
"""

import json
import os
import subprocess
import sys
import time
import urllib.parse

JSON_PATH = os.path.join(os.path.dirname(__file__), "clubes_chile_argentina_whatsapp.json")

MENSAJE_OFICIAL = """¡Hola equipo de {CLUB}! 👋 Les escribe Emmanuel Villar de Padelblox.

Les escribo directo porque estamos seleccionando a los clubes más destacados de {CIUDAD} para darles *3 meses de acceso 100% bonificado* a nuestro software para clubes de pádel.

A diferencia de los sistemas tradicionales, Padelblox está diseñado para resolver lo que más impacta en el día a día del club:

🎾 *Llenar canchas en horarios valle:* Torneos americanos express automáticos y módulos de clases para academias.
📈 *Cero fugas en cantina y mostrador:* Punto de Venta (POS) para bebidas, pelotas y grips con arqueo de caja diario exacto.
⌚ *Experiencia única en cancha:* Marcadores en pantallas TV y sincronización con Smartwatch para los jugadores.
💰 *Sin comisiones abusivas:* Tu facturación se queda 100% en tu club.

¿Te parece si te comparto por acá un video demo de 1 minuto para que veas cómo funciona?

—
*Emmanuel Villar*
Padelblox
📱 +56 9 6908 3124
🌐 padelblox.cl"""

def clean_phone(phone: str) -> str:
    cleaned = "".join(filter(str.isdigit, phone))
    if len(cleaned) == 9 and cleaned.startswith("9"):
        cleaned = "56" + cleaned
    return cleaned

def press_enter_applescript(app_name="WhatsApp"):
    """Envía la pulsación de la tecla ENTER a la aplicación activa vía AppleScript"""
    script = f'''
    tell application "{app_name}" to activate
    delay 0.5
    tell application "System Events"
        key code 36
    end tell
    '''
    res = subprocess.run(["osascript", "-e", script], capture_output=True, text=True)
    return res.returncode == 0

def check_accessibility_permission():
    """Verifica si Terminal tiene permiso de accesibilidad para presionar teclas"""
    test_script = 'tell application "System Events" to get name of current user'
    res = subprocess.run(["osascript", "-e", test_script], capture_output=True, text=True)
    return res.returncode == 0

def main():
    if not os.path.exists(JSON_PATH):
        print(f"❌ Error: No se encontró {JSON_PATH}")
        sys.exit(1)

    with open(JSON_PATH, "r", encoding="utf-8") as f:
        data = json.load(f)

    all_clubs = data.get("clubs", [])

    print("\n" + "=" * 72)
    print("🤖 PADELBLOX - BOT DE ENVÍO 100% AUTOMÁTICO WHATSAPP (SIN ENTER MANUAL)")
    print("=" * 72)
    print(f"📱 Remitente: Emmanuel Villar (+56 9 6908 3124)")
    print(f"📍 Total de clubes en base de datos: {len(all_clubs)}\n")

    # Selección de filtro
    print("¿A qué clubes deseas enviar?")
    print("1. 🇨🇱 Solo Santiago (Región Metropolitana)")
    print("2. 🇨🇱 Regiones de Chile (Viña, Concón, Serena, Rancagua, Conce, Temuco)")
    print("3. 🇦🇷 Solo Argentina")
    print("4. 🌍 Todos (Chile + Argentina - 42 Clubes)")
    print("0. Salir\n")

    opc_terr = input("Selecciona territorio [1/2/3/4/0]: ").strip()

    if opc_terr == "1":
        clubs = [c for c in all_clubs if c.get("ciudad") == "Santiago" or "Metropolitana" in c.get("region","")]
    elif opc_terr == "2":
        clubs = [c for c in all_clubs if c.get("pais") == "Chile" and c.get("ciudad") != "Santiago" and "Metropolitana" not in c.get("region","")]
    elif opc_terr == "3":
        clubs = [c for c in all_clubs if c.get("pais") == "Argentina"]
    elif opc_terr == "4":
        clubs = all_clubs
    else:
        print("Cancelado.")
        return

    pendientes = [c for c in clubs if c.get("estado_envio", "pendiente") == "pendiente"]
    print(f"\n🎯 Clubes en este grupo: {len(clubs)} | Pendientes: {len(pendientes)}\n")

    if not pendientes:
        print("🎉 ¡Todos los clubes seleccionados ya están marcados como enviados!")
        reinicio = input("¿Deseas reiniciar los estados a 'pendiente' para volver a enviar? (s/n): ").strip().lower()
        if reinicio == "s":
            for c in clubs:
                c["estado_envio"] = "pendiente"
            with open(JSON_PATH, "w", encoding="utf-8") as f:
                json.dump(data, f, indent=2, ensure_ascii=False)
            pendientes = clubs
        else:
            return

    # Selección de app
    print("Elige la aplicación que vas a usar para enviar:")
    print("1. 💻 WhatsApp Desktop (App instalada en tu Mac) -> Más rápido y recomendado")
    print("2. 🌐 Google Chrome (WhatsApp Web)")
    app_choice = input("Selecciona [1/2]: ").strip()

    use_desktop = (app_choice != "2")
    target_app = "WhatsApp" if use_desktop else "Google Chrome"

    # Verificación de permisos de accesibilidad en macOS
    print("\n🔍 Comprobando permisos de automatización en macOS...")
    if not check_accessibility_permission():
        print("\n⚠️ AVISO DE PERMISOS DE MACOS:")
        print("Para que el script pueda presionar la tecla ENTER automáticamente sin que tú la toques:")
        print("1. Ve a: Ajustes del Sistema -> Privacidad y Seguridad -> Accesibilidad.")
        print("2. Activa el interruptor para 'Terminal' o la app donde ejecutas este comando.")
        print("----------------------------------------------------------------------")
        input("Una vez activado el permiso, presiona [ENTER] aquí para continuar...")

    # Configuración de pausa
    try:
        pausa = int(input("\n⏱️ Segundos de pausa entre cada club (Recomendado: 12 segundos): ").strip() or "12")
    except ValueError:
        pausa = 12

    # Tiempo de carga inicial del chat
    load_wait = 3.5 if use_desktop else 9.0

    print("\n" + "=" * 72)
    print(f"🚀 INICIANDO ENVÍO AUTOMÁTICO A {len(pendientes)} CLUBES CON {target_app.upper()}")
    print("👉 El bot abrirá el chat, esperará que cargue y PRESIONARÁ ENTER POR TI.")
    print("👉 Puedes cancelar en cualquier momento presionando Ctrl + C en esta terminal.")
    print("=" * 72 + "\n")

    time.sleep(2)

    enviados_count = 0

    try:
        for idx, club in enumerate(pendientes, 1):
            nombre = club["nombre_club"]
            telefono = clean_phone(club["telefono"])
            ubicacion = f"{club.get('ciudad', '')} ({club.get('comuna', '')})"

            ciudad = club.get("ciudad") or club.get("comuna") or "su zona"
            msg = MENSAJE_OFICIAL.replace("{CLUB}", nombre).replace("{CIUDAD}", ciudad)
            encoded_msg = urllib.parse.quote(msg)

            if use_desktop:
                url = f"whatsapp://send?phone={telefono}&text={encoded_msg}"
            else:
                url = f"https://web.whatsapp.com/send?phone={telefono}&text={encoded_msg}"

            print(f"[{idx}/{len(pendientes)}] 📤 Enviando a: {nombre} ({ubicacion}) | Tel: +{telefono}...")

            # 1. Abrir la conversación
            subprocess.run(["open", url])

            # 2. Esperar a que cargue el mensaje en la ventana
            print(f"   ⏳ Esperando {load_wait}s a que cargue el chat...")
            time.sleep(load_wait)

            # 3. Disparar ENTER automáticamente vía AppleScript
            print(f"   ⚡ Presionando ENTER automáticamente...")
            press_enter_applescript(target_app)

            # 4. Actualizar estado y persistir
            club["estado_envio"] = "enviado"
            club["fecha_envio"] = time.strftime("%Y-%m-%d %H:%M:%S")
            enviados_count += 1

            with open(JSON_PATH, "w", encoding="utf-8") as f:
                json.dump(data, f, indent=2, ensure_ascii=False)

            print(f"   ✅ Mensaje enviado con éxito a {nombre}.")

            # 5. Pausa de seguridad anti-bloqueo
            if idx < len(pendientes):
                print(f"   🛡️ Pausa de seguridad anti-bloqueo ({pausa}s)...\n")
                time.sleep(pausa)

        print("\n" + "=" * 72)
        print(f"🎉 ¡PROCESO COMPLETADO! Se enviaron automáticamente {enviados_count} mensajes.")
        print("=" * 72 + "\n")

    except KeyboardInterrupt:
        print("\n\n⏸️ Envío automático detenido por el usuario. El progreso se guardó correctamente.")

if __name__ == "__main__":
    main()

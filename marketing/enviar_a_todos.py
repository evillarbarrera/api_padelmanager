#!/usr/bin/env python3
"""
Padelblox - Envío Masivo Asistido por WhatsApp (Chile & Argentina)
Abre automáticamente la conversación de cada club con el mensaje pre-cargado.
"""

import json
import os
import sys
import time
import urllib.parse
import webbrowser

JSON_INTL = os.path.join(os.path.dirname(__file__), "clubes_chile_argentina_whatsapp.json")
JSON_STGO = os.path.join(os.path.dirname(__file__), "clubes_santiago_whatsapp.json")

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

def main():
    json_file = JSON_INTL if os.path.exists(JSON_INTL) else JSON_STGO
    if not os.path.exists(json_file):
        print(f"❌ Error: No se encontró el archivo de clubes.")
        sys.exit(1)

    with open(json_file, "r", encoding="utf-8") as f:
        data = json.load(f)

    all_clubs = data.get("clubs", [])

    print("\n" + "=" * 70)
    print("🎾 PADELBLOX - ENVÍO MASIVO WHATSAPP (CHILE & ARGENTINA 2026)")
    print(f"📱 Remitente: Emmanuel Villar (+56 9 6908 3124)")
    print(f"📍 Total de clubes en base de datos: {len(all_clubs)}")
    print("=" * 70 + "\n")

    print("Selecciona el territorio a prospectar:")
    print("1. 🌍 Todos los Clubes (Chile + Argentina)")
    print("2. 🇨🇱 Solo Santiago (Región Metropolitana)")
    print("3. 🇨🇱 Solo Regiones de Chile (Viña, Concón, Serena, Rancagua, Conce, Temuco)")
    print("4. 🇦🇷 Solo Argentina (CABA, GBA Norte, Rosario, Córdoba, Mendoza)")
    print("0. Salir\n")

    terr = input("Elige una opción [1/2/3/4/0]: ").strip()

    if terr == "1":
        clubs = all_clubs
    elif terr == "2":
        clubs = [c for c in all_clubs if c.get("ciudad") == "Santiago" or "Metropolitana" in c.get("region","")]
    elif terr == "3":
        clubs = [c for c in all_clubs if c.get("pais") == "Chile" and c.get("ciudad") != "Santiago" and "Metropolitana" not in c.get("region","")]
    elif terr == "4":
        clubs = [c for c in all_clubs if c.get("pais") == "Argentina"]
    else:
        print("Saliendo.")
        return

    pendientes = [c for c in clubs if c.get("estado_envio", "pendiente") == "pendiente"]
    print(f"\n🎯 Clubes seleccionados: {len(clubs)} (Pendientes: {len(pendientes)})\n")

    if not pendientes:
        print("🎉 ¡Todos los clubes de este grupo ya figuran como enviados!")
        reiniciar = input("¿Deseas reiniciar los estados a 'pendiente' para volver a enviar? (s/n): ").strip().lower()
        if reiniciar == "s":
            for c in clubs:
                c["estado_envio"] = "pendiente"
            with open(json_file, "w", encoding="utf-8") as f:
                json.dump(data, f, indent=2, ensure_ascii=False)
            pendientes = clubs
        else:
            return

    print("Modalidad de envío:")
    print("1. ⚡ Modo Secuencial Manual: Pulsa [ENTER] para abrir el chat del siguiente club.")
    print("2. ⏱️ Modo Piloto Automático: Abre un club cada X segundos automáticamente.")
    print("0. Cancelar\n")

    modo = input("Elige una opción [1/2/0]: ").strip()

    if modo == "1":
        print("\n🚀 Iniciando Modo Secuencial Manual...")
        enviados = 0
        for idx, c in enumerate(pendientes, 1):
            nombre = c["nombre_club"]
            telefono = clean_phone(c["telefono"])
            ubicacion = f"{c.get('pais', '')} - {c.get('ciudad', '')} ({c.get('comuna', '')})"

            ciudad = c.get("ciudad") or c.get("comuna") or "su zona"
            msg = MENSAJE_OFICIAL.replace("{CLUB}", nombre).replace("{CIUDAD}", ciudad)
            url = f"https://web.whatsapp.com/send?phone={telefono}&text={urllib.parse.quote(msg)}"

            print(f"\n[{idx}/{len(pendientes)}] 👉 {nombre} | {ubicacion} | Tel: +{telefono}")
            confirm = input("Presiona [ENTER] para abrir chat (o 's' saltar, 'q' salir): ").strip().lower()

            if confirm == "q":
                print("Operación pausada.")
                break
            elif confirm == "s":
                print(f"⏭️ Saltado: {nombre}")
                continue

            webbrowser.open(url)
            c["estado_envio"] = "enviado"
            c["fecha_envio"] = time.strftime("%Y-%m-%d %H:%M:%S")
            enviados += 1

            with open(json_file, "w", encoding="utf-8") as f:
                json.dump(data, f, indent=2, ensure_ascii=False)

        print(f"\n✅ Proceso completado. Total enviados en esta sesión: {enviados}")

    elif modo == "2":
        try:
            segundos = int(input("\n¿Cuántos segundos de pausa entre cada club? (Recomendado: 12): ").strip() or "12")
        except ValueError:
            segundos = 12

        print(f"\n🚀 Iniciando Piloto Automático con pausa de {segundos} segundos...")
        print("Presiona Ctrl+C en cualquier momento para detener.\n")

        try:
            for idx, c in enumerate(pendientes, 1):
                nombre = c["nombre_club"]
                telefono = clean_phone(c["telefono"])
                ubicacion = f"{c.get('ciudad', '')} ({c.get('comuna', '')})"

                ciudad = c.get("ciudad") or c.get("comuna") or "su zona"
                msg = MENSAJE_OFICIAL.replace("{CLUB}", nombre).replace("{CIUDAD}", ciudad)
                url = f"https://web.whatsapp.com/send?phone={telefono}&text={urllib.parse.quote(msg)}"

                print(f"[{idx}/{len(pendientes)}] Abriendo WhatsApp para: {nombre} ({ubicacion})...")
                webbrowser.open(url)

                c["estado_envio"] = "enviado"
                c["fecha_envio"] = time.strftime("%Y-%m-%d %H:%M:%S")

                with open(json_file, "w", encoding="utf-8") as f:
                    json.dump(data, f, indent=2, ensure_ascii=False)

                if idx < len(pendientes):
                    print(f"⏳ Pausa de {segundos}s para evitar bloqueos...")
                    time.sleep(segundos)

            print("\n🎉 ¡Listo! Se abrieron los chats de todos los clubes seleccionados.")
        except KeyboardInterrupt:
            print("\n\n⏸️ Envío pausado. El progreso ha quedado guardado.")

    else:
        print("Operación cancelada.")

if __name__ == "__main__":
    main()

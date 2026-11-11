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

MENSAJE_OFICIAL = """¡Hola equipo de {CLUB}! 👋 Les escribe Emmanuel Villar de Padelblox Chile.

Sé que para todo club de pádel uno de los mayores desafíos es rentabilizar las canchas en horarios diurnos/valle (09:00 a 17:00) y evitar fugas en cobros de cantina y clases.

En Padelblox desarrollamos un software todo-en-uno que conecta las reservas con torneos americanos express, punto de venta (POS) y las clases de tus entrenadores para llenar esos horarios.

Estamos seleccionando clubes clave en Santiago para darles *3 meses de acceso completo 100% gratis* sin ningún compromiso.

¿Te gustaría que coordinemos una demo rápida de 5 min por acá?

—
*Emmanuel Villar*
Padelblox Chile
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

            msg = MENSAJE_OFICIAL.replace("{CLUB}", nombre)
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

                msg = MENSAJE_OFICIAL.replace("{CLUB}", nombre)
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

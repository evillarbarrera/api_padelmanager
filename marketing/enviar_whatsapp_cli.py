#!/usr/bin/env python3
"""
CLI & Helper de Envío de Mensajes por WhatsApp a Clubes de Pádel en Santiago
Padelblox Growth Engine 2026
"""

import json
import os
import sys
import urllib.parse
import webbrowser

JSON_PATH = os.path.join(os.path.dirname(__file__), "clubes_santiago_whatsapp.json")

NUMERO_PADELBLOX = "+56 9 6908 3124"

PLANTILLA_DEFAULT = """¡Hola equipo de {CLUB}! 👋 Les escribe Emmanuel Villar de Padelblox Chile.

Sé que para todo club de pádel uno de los mayores desafíos es rentabilizar las canchas en horarios diurnos/valle (09:00 a 17:00) y evitar desorden en cobros de cantina y clases.

En Padelblox desarrollamos un software integral que conecta las reservas con torneos americanos automáticos, punto de venta (POS) y las clases de tus entrenadores para llenar esos horarios.

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
    if not os.path.exists(JSON_PATH):
        print(f"❌ Error: No se encontró el archivo {JSON_PATH}")
        sys.exit(1)

    with open(JSON_PATH, "r", encoding="utf-8") as f:
        data = json.load(f)

    clubs = data.get("clubs", [])
    print(f"\n🎾 PADELBLOX - Campaña de Prospección WhatsApp Santiago")
    print(f"📍 Total de clubes cargados: {len(clubs)}\n")

    print("Comandos disponibles:")
    print("1. Abrir Panel Interactivo Web (Recomendado)")
    print("2. Ver listado de clubes y teléfonos en consola")
    print("3. Abrir WhatsApp 1 a 1 paso a paso en el navegador")
    print("0. Salir\n")

    opcion = input("Selecciona una opción [1/2/3/0]: ").strip()

    if opcion == "1":
        html_path = os.path.join(os.path.dirname(__file__), "whatsapp_campaign_santiago.html")
        print(f"\n🚀 Abriendo panel web en tu navegador: {html_path}")
        webbrowser.open("file://" + os.path.abspath(html_path))

    elif opcion == "2":
        print("\n" + "=" * 80)
        print(f"{'#':<4} {'Club':<30} {'Comuna':<20} {'WhatsApp':<16} {'Estado'}")
        print("=" * 80)
        for c in clubs:
            print(f"{c['id']:<4} {c['nombre_club'][:28]:<30} {c['comuna'][:18]:<20} {c['telefono']:<16} {c.get('estado_envio','pendiente')}")
        print("=" * 80 + "\n")

    elif opcion == "3":
        print("\n🚀 Iniciando modo asistido paso a paso:")
        print("Cada vez que presiones [ENTER], se abrirá la conversación en WhatsApp con el mensaje personalizado.")
        print("Escribe 's' para saltar un club, o 'q' para salir.\n")

        for c in clubs:
            nombre = c["nombre_club"]
            telefono = c["telefono"]
            clean_num = clean_phone(telefono)
            msg = PLANTILLA_DEFAULT.replace("{CLUB}", nombre)
            encoded_msg = urllib.parse.quote(msg)
            url = f"https://web.whatsapp.com/send?phone={clean_num}&text={encoded_msg}"

            print(f"\n👉 Siguiente club: {nombre} ({c['comuna']}) | Tel: {telefono}")
            action = input("Presiona ENTER para abrir WhatsApp (o 's' saltar, 'q' salir): ").strip().lower()

            if action == "q":
                print("Operación cancelada.")
                break
            elif action == "s":
                print(f"Saltado {nombre}.")
                continue
            else:
                c["estado_envio"] = "enviado"
                webbrowser.open(url)
                print(f"✅ Abierto en WhatsApp Web para {nombre}.")

        with open(JSON_PATH, "w", encoding="utf-8") as f:
            json.dump(data, f, indent=2, ensure_ascii=False)
        print("\n💾 Estados actualizados en el archivo JSON.")

    else:
        print("Saliendo.")

if __name__ == "__main__":
    main()

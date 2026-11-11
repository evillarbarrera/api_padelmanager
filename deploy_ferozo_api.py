#!/usr/bin/env python3
"""
PadelBlox API - Despliegue a Hosting Ferozo / DonWeb vía FTPS / FTP (Puerto 21)
No requiere apt-get ni dependencias externas (usa la biblioteca estándar de Python 3).
"""

import os
import sys
import ftplib
import ssl
from pathlib import Path

# Cargar variables desde .env.deploy si existe
env_path = Path(__file__).parent / ".env.deploy"
env_vars = {}

if env_path.exists():
    with open(env_path, "r", encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if line and not line.startswith("#") and "=" in line:
                k, v = line.split("=", 1)
                env_vars[k.strip()] = v.strip().strip('"').strip("'")

SERVER = os.environ.get("SFTP_SERVER", env_vars.get("SFTP_SERVER", "c2632100.ferozo.com"))
USERNAME = os.environ.get("SFTP_USERNAME", env_vars.get("SFTP_USERNAME", "c2632100"))
PASSWORD = os.environ.get("SFTP_PASSWORD", env_vars.get("SFTP_PASSWORD", ""))
PORT = int(os.environ.get("SFTP_PORT", env_vars.get("SFTP_PORT", 21)))
REMOTE_DIR = os.environ.get("SFTP_REMOTE_DIR", env_vars.get("SFTP_REMOTE_DIR", "/public_html/api/dev/"))
SOURCE_DIR = os.environ.get("DEPLOY_SOURCE_DIR", ".deploy_staging")

class ExplicitFTP_TLS(ftplib.FTP_TLS):
    def ntransfercmd(self, cmd, rest=None):
        conn, size = ftplib.FTP.ntransfercmd(self, cmd, rest)
        if self._prot_p:
            session = getattr(self.sock, "session", None)
            conn = self.context.wrap_socket(conn,
                                             server_hostname=self.host,
                                             session=session)
        return conn, size

def connect_ftp():
    print(f"🎾 Conectando a {SERVER}:{PORT} como '{USERNAME}'...")
    try:
        context = ssl.create_default_context()
        context.check_hostname = False
        context.verify_mode = ssl.CERT_NONE
        
        ftps = ExplicitFTP_TLS(context=context)
        ftps.connect(SERVER, PORT, timeout=60)
        try:
            ftps.auth()
        except Exception:
            pass
            
        ftps.login(USERNAME, PASSWORD)
        try:
            ftps.prot_p()
            print("✅ Conectado vía FTPS (PROT P).")
        except Exception:
            try:
                ftps.prot_c()
                print("✅ Conectado vía FTPS (PROT C).")
            except Exception:
                print("✅ Conectado vía FTPS.")
        return ftps
    except Exception as tls_err:
        print(f"⚠️  FTPS no disponible ({tls_err}). Intentando FTP estándar...")
        try:
            ftp = ftplib.FTP()
            ftp.connect(SERVER, PORT, timeout=60)
            ftp.login(USERNAME, PASSWORD)
            print("✅ Conectado vía FTP estándar.")
            return ftp
        except Exception as ftp_err:
            print(f"❌ Error conectando a Ferozo: {ftp_err}")
            sys.exit(1)

def ensure_remote_dir(ftps, path):
    parts = [p for p in path.strip("/").split("/") if p]
    cur = ""
    for p in parts:
        cur += "/" + p
        try:
            ftps.cwd(cur)
        except Exception:
            try:
                ftps.mkd(cur)
                ftps.cwd(cur)
            except Exception:
                pass

def main():
    source_path = Path(SOURCE_DIR)
    if not source_path.exists():
        print(f"ℹ️ La carpeta fuente '{SOURCE_DIR}' no existe o está vacía. Nada que subir.")
        return

    ftps = connect_ftp()
    print(f"🚀 Sincronizando API desde '{source_path}' hacia '{REMOTE_DIR}'...")
    ensure_remote_dir(ftps, REMOTE_DIR)

    uploaded = 0
    for root, _, files in os.walk(source_path):
        rel_dir = os.path.relpath(root, source_path)
        target_remote = REMOTE_DIR.rstrip('/') if rel_dir == "." else f"{REMOTE_DIR.rstrip('/')}/{rel_dir}"
        ensure_remote_dir(ftps, target_remote)
        ftps.cwd(target_remote)

        for file in sorted(files):
            local_file = Path(root) / file
            local_size = local_file.stat().st_size
            try:
                remote_size = ftps.size(file)
                if remote_size == local_size:
                    print(f"  ⏭️ {file} (sin cambios, omitido)")
                    continue
            except Exception:
                pass

            with open(local_file, "rb") as fp:
                ftps.storbinary(f"STOR {file}", fp)
                uploaded += 1
                print(f"  ✅ {file}")

    # Procesar archivos eliminados si existe el archivo
    deleted_list_file = Path(".deleted_files.txt")
    if deleted_list_file.exists():
        with open(deleted_list_file, "r", encoding="utf-8") as df:
            for line in df:
                f_del = line.strip()
                if not f_del:
                    continue
                remote_file_path = f"{REMOTE_DIR.rstrip('/')}/{f_del}"
                try:
                    ftps.delete(remote_file_path)
                    print(f"  🗑️ Eliminado en remoto: {remote_file_path}")
                except Exception:
                    pass

    try:
        ftps.quit()
    except Exception:
        ftps.close()

    print(f"\n🎉 ¡Despliegue de API completado con éxito! {uploaded} archivo(s) procesados en {REMOTE_DIR}.")

if __name__ == "__main__":
    main()

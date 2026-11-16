"""HMAC-SHA256 de un backup cifrado (encrypt-then-MAC). SEC-09.

openssl enc (AES-256-CBC) no autentica: sin un MAC, quien pudiera escribir
en el destino de los backups podría alterar el archivo cifrado y forzar que
se descifre y extraiga contenido manipulado. Este MAC se verifica ANTES de
descifrar.

La clave de HMAC se deriva de la misma frase de BACKUP_CLAVE_ARCHIVO
(SHA-256 con separación de dominio). Se lee desde el archivo y nunca pasa
por la línea de comandos (no queda visible en la lista de procesos).

Uso:
    python3 -I hmac.py firmar     <archivo-clave> <archivo>   # imprime el MAC en hex
    python3 -I hmac.py verificar  <archivo-clave> <archivo> <archivo.hmac>
"""
import hashlib
import hmac
import sys


def clave(ruta_clave: str) -> bytes:
    with open(ruta_clave, 'rb') as f:
        frase = f.read().strip()
    if not frase:
        sys.exit('clave vacía')
    return hashlib.sha256(b'remiseria-backup-hmac-v1\x00' + frase).digest()


def mac(ruta_clave: str, ruta: str) -> str:
    h = hmac.new(clave(ruta_clave), digestmod=hashlib.sha256)
    with open(ruta, 'rb') as f:
        for bloque in iter(lambda: f.read(1024 * 1024), b''):
            h.update(bloque)
    return h.hexdigest()


def main() -> int:
    if len(sys.argv) == 4 and sys.argv[1] == 'firmar':
        print(mac(sys.argv[2], sys.argv[3]))
        return 0
    if len(sys.argv) == 5 and sys.argv[1] == 'verificar':
        with open(sys.argv[4], encoding='ascii') as f:
            esperado = f.read().split()[0].strip().lower()
        return 0 if hmac.compare_digest(mac(sys.argv[2], sys.argv[3]), esperado) else 1
    print(__doc__, file=sys.stderr)
    return 2


if __name__ == '__main__':
    sys.exit(main())

#!/usr/bin/env python3
"""Minimal read-only TFTP server (RFC 1350 + blksize option, RFC 2348) for
loading kernels into U-Boot. Serves files from one directory only.

    /usr/bin/python3 kit/tftpd.py --root out --bind 0.0.0.0

Use Apple's /usr/bin/python3: macOS Local Network privacy blocked pyenv's
python from reaching the LAN. Unprivileged binding to port 69 works on macOS."""
import argparse, os, socket, struct, sys, threading, time

def serve(addr, path, opts, bind):
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    s.bind(("0.0.0.0", 0)); s.settimeout(1.0)
    blksize = 512
    oack = b""
    if "blksize" in opts:
        blksize = max(8, min(1468, int(opts["blksize"])))
        oack += b"blksize\0" + str(blksize).encode() + b"\0"
    size = os.path.getsize(path)
    if "tsize" in opts:
        oack += b"tsize\0" + str(size).encode() + b"\0"
    f = open(path, "rb")
    def send_wait(pkt, expect_block):
        for _ in range(8):
            s.sendto(pkt, addr)
            t0 = time.time()
            while time.time() - t0 < 1.0:
                try:
                    data, a = s.recvfrom(1500)
                except socket.timeout:
                    break
                if a != addr or len(data) < 4: continue
                op, blk = struct.unpack("!HH", data[:4])
                if op == 4 and blk == expect_block: return True
                if op == 5: return False
        return False
    if oack and not send_wait(b"\0\x06" + oack, 0):
        print(f"[tftpd] {addr} no ACK for OACK", flush=True); return
    blk, sent, t0 = 1, 0, time.time()
    while True:
        chunk = f.read(blksize)
        if not send_wait(struct.pack("!HH", 3, blk & 0xFFFF) + chunk, blk & 0xFFFF):
            print(f"[tftpd] {addr} {os.path.basename(path)} aborted at block {blk}", flush=True); return
        sent += len(chunk)
        if len(chunk) < blksize: break
        blk += 1
    dt = time.time() - t0
    print(f"[tftpd] {addr} {os.path.basename(path)} {sent} bytes in {dt:.1f}s ({sent/dt/1024:.0f} KB/s)", flush=True)

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--root", required=True); ap.add_argument("--bind", default="0.0.0.0")
    ap.add_argument("--port", type=int, default=69)
    a = ap.parse_args()
    root = os.path.realpath(a.root)
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM); s.bind((a.bind, a.port))
    print(f"[tftpd] serving {root} on {a.bind}:{a.port}", flush=True)
    while True:
        data, addr = s.recvfrom(1500)
        if len(data) < 4 or struct.unpack("!H", data[:2])[0] != 1: continue
        parts = data[2:].split(b"\0")
        name = parts[0].decode(errors="replace").lstrip("/")
        opts = {parts[i].decode().lower(): parts[i+1].decode() for i in range(2, len(parts) - 1, 2) if parts[i]}
        path = os.path.realpath(os.path.join(root, name))
        print(f"[tftpd] {addr} RRQ {name} {opts}", flush=True)
        if not path.startswith(root + os.sep) or not os.path.isfile(path):
            s.sendto(b"\0\x05\0\x01File not found\0", addr); continue
        threading.Thread(target=serve, args=(addr, path, opts, a.bind), daemon=True).start()

if __name__ == "__main__":
    main()

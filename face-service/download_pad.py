"""Fetch a pinned Open Model Zoo anti-spoof classifier; reject changed weights."""
import hashlib
from pathlib import Path
from urllib.request import urlopen

MODEL_URL = 'https://storage.openvinotoolkit.org/repositories/open_model_zoo/public/2022.1/anti-spoof-mn3/anti-spoof-mn3.onnx'
MODEL_SHA384 = '6de4534964b723397b3e8c995cadcf43bc007cc2f9930b95ae25f76adccece5d1d4d058d0b15117b9e4a9f758424f92a'

def download(folder='models'):
    target = Path(folder) / 'anti-spoof-mn3.onnx'
    target.parent.mkdir(parents=True, exist_ok=True)
    with urlopen(MODEL_URL, timeout=120) as response:
        data = response.read(12_270_180)
    if len(data) != 12_270_179 or hashlib.sha384(data).hexdigest() != MODEL_SHA384:
        raise RuntimeError('Anti-spoof model checksum mismatch. Build stopped.')
    target.write_bytes(data)

if __name__ == '__main__': download()

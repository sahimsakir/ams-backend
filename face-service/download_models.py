"""Fetch pinned Apache-2.0 MiniVision weights; fail build on any mismatch."""
import hashlib
import json
from pathlib import Path
from urllib.request import urlopen

root = Path(__file__).parent / 'minivision'
revision = 'b6d5f04ad78778917853b25c778acef6d5626d15'
for name, digest in json.loads((root / 'weights.json').read_text()).items():
    with urlopen(f'https://raw.githubusercontent.com/minivision-ai/Silent-Face-Anti-Spoofing/{revision}/resources/anti_spoof_models/{name}', timeout=60) as response:
        data = response.read(5 * 1024 * 1024)
    if hashlib.sha256(data).hexdigest() != digest:
        raise RuntimeError(f'Model checksum mismatch: {name}')
    (root / name).write_bytes(data)

FROM python:3.11-slim-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends build-essential cmake libopenblas-dev && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY app.py motion.py passive.py download_pad.py test_motion.py test_passive.py ANTI-SPOOF-LICENSE.txt ./
RUN python download_pad.py
RUN useradd -m face
USER face
CMD ["uvicorn","app:app","--host","0.0.0.0","--port","8001","--workers","1"]

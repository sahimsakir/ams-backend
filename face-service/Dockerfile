FROM python:3.11-slim-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends build-essential cmake libopenblas-dev && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
RUN pip install --no-cache-dir torch==2.6.0 --index-url https://download.pytorch.org/whl/cpu
COPY minivision ./minivision
COPY download_models.py ./
RUN python download_models.py
COPY app.py motion.py liveness.py test_motion.py test_liveness.py ./
RUN python -c "from liveness import PassiveLiveness; PassiveLiveness()"
RUN useradd -m face
USER face
CMD ["uvicorn","app:app","--host","0.0.0.0","--port","8001","--workers","1"]

<?php

return ['frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'), 'face_url' => env('FACE_URL', 'http://face:8001'), 'face_key' => env('FACE_SERVICE_KEY'), 'live_min_score' => (float) env('FACE_LIVE_MIN_SCORE', 0.95), 'threshold' => (float) env('FACE_THRESHOLD', 0.45)];

"""Geometric challenge checks; not a certified anti-spoofing model."""
import numpy as np

def eye_ratio(eye):
    p = np.asarray(eye, dtype=float)
    return float((np.linalg.norm(p[1]-p[5]) + np.linalg.norm(p[2]-p[4])) / (2 * max(np.linalg.norm(p[0]-p[3]), 1)))

def metrics(landmarks):
    left = np.mean(landmarks['left_eye'], axis=0)
    right = np.mean(landmarks['right_eye'], axis=0)
    center = (left + right) / 2
    axis = right - left
    axis /= max(np.linalg.norm(axis), 1)
    down = np.array([-axis[1], axis[0]])
    nose = np.asarray(landmarks['nose_tip'][2])
    chin = np.asarray(landmarks['chin'][8])
    span = max(np.linalg.norm(right-left), 1)
    return {'yaw': float(np.dot(nose-center, axis)/span),
            'pitch': float(np.dot(nose-center, down)/max(np.dot(chin-center, down), 1)),
            'ear': (eye_ratio(landmarks['left_eye']) + eye_ratio(landmarks['right_eye']))/2}

def verify_motion(pose, samples, baseline):
    if pose == 'front' and baseline is None:
        if any(abs(m['yaw']) > .18 or not .22 < m['pitch'] < .65 or m['ear'] < .18 for m in samples):
            raise ValueError('Look straight at the camera with eyes open')
        return {key: float(np.median([m[key] for m in samples])) for key in ('yaw', 'pitch', 'ear')}
    if not baseline:
        raise ValueError('Missing baseline')
    if pose == 'blink':
        # Require open -> closed -> open in that order, relative to this person's eyes.
        stage = 0
        for m in samples:
            if abs(m['yaw']-baseline['yaw']) > .16 or abs(m['pitch']-baseline['pitch']) > .12:
                continue
            opened = m['ear'] >= baseline['ear'] * .85
            closed = m['ear'] < baseline['ear'] * .64
            if stage == 0 and opened: stage = 1
            elif stage == 1 and closed: stage = 2
            elif stage == 2 and opened: stage = 3
        if stage != 3: raise ValueError('Slowly close and reopen your eyes')
    else:
        def matches(m):
            yaw, pitch = m['yaw']-baseline['yaw'], m['pitch']-baseline['pitch']
            # User's anatomical left moves the nose right in the unmirrored image.
            return {'left': yaw > .13 and abs(pitch) < .15,
                    'right': yaw < -.13 and abs(pitch) < .15,
                    'up': pitch < -.08 and abs(yaw) < .18,
                    'down': pitch > .08 and abs(yaw) < .18,
                    'front': abs(yaw) < .10 and abs(pitch) < .07 and m['ear'] > baseline['ear']*.8}.get(pose, False)
        if sum(matches(m) for m in samples) < max(3, len(samples)//2):
            raise ValueError('Follow the requested head direction')
    return baseline


<?php
// ============================================================
// lecture_recorder.php — NoteNest AI Platform
// Web Audio Lecture Recorder + Recording Management
// ============================================================
require 'includes/auth.php';
require 'config.php';

$user_id = $_SESSION['user_id'];

// ── AJAX Handlers ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    // ── Save recording ────────────────────────────────────────
    if ($_POST['action'] === 'save_recording') {
        if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success'=>false,'error'=>'Upload failed.']); exit;
        }
        $title     = trim($_POST['title']     ?? 'Recording '.date('d M H:i'));
        $course_id = (int)($_POST['course_id'] ?? 0);
        $duration  = (int)($_POST['duration']  ?? 0);

        $ext = 'webm'; // MediaRecorder default
        $dir = 'uploads/recordings/';
        if (!is_dir(__DIR__.'/'.$dir)) mkdir(__DIR__.'/'.$dir, 0777, true);

        $fname = 'rec_'.$user_id.'_'.time().'.'.$ext;
        $fpath = $dir . $fname;

        if (move_uploaded_file($_FILES['audio']['tmp_name'], __DIR__.'/'.$fpath)) {
            $fsize = filesize(__DIR__.'/'.$fpath);
            $courseIdVal = $course_id > 0 ? $course_id : null;

            $stmt = $conn->prepare(
                "INSERT INTO lecture_recordings (user_id, course_id, title, file_path, duration_sec, file_size)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('iissii', $user_id, $courseIdVal, $title, $fpath, $duration, $fsize);
            $stmt->execute();
            echo json_encode(['success'=>true, 'id'=>$conn->insert_id, 'path'=>$fpath]);
        } else {
            echo json_encode(['success'=>false,'error'=>'Could not save file.']);
        }
        exit;
    }

    // ── Delete recording ──────────────────────────────────────
    if ($_POST['action'] === 'delete_recording') {
        $rid = (int)($_POST['rec_id'] ?? 0);
        $stmt = $conn->prepare("SELECT file_path FROM lecture_recordings WHERE id=? AND user_id=?");
        $stmt->bind_param('ii', $rid, $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            @unlink(__DIR__.'/'.$row['file_path']);
            $conn->query("DELETE FROM lecture_recordings WHERE id=$rid");
            echo json_encode(['success'=>true]);
        } else {
            echo json_encode(['success'=>false]);
        }
        exit;
    }

    // ── Update title ──────────────────────────────────────────
    if ($_POST['action'] === 'update_title') {
        $rid   = (int)($_POST['rec_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $stmt  = $conn->prepare("UPDATE lecture_recordings SET title=? WHERE id=? AND user_id=?");
        $stmt->bind_param('sii', $title, $rid, $user_id);
        $stmt->execute();
        echo json_encode(['success'=> $stmt->affected_rows > 0]);
        exit;
    }

    echo json_encode(['success'=>false,'error'=>'Unknown action']); exit;
}

// ── Load recordings & courses ─────────────────────────────────
$recordings = [];
$rq = $conn->prepare(
    "SELECT r.id, r.title, r.file_path, r.duration_sec, r.file_size, r.status, r.created_at,
            c.name AS course_name, c.color AS course_color
     FROM lecture_recordings r
     LEFT JOIN courses c ON r.course_id = c.id
     WHERE r.user_id=? ORDER BY r.created_at DESC"
);
$rq->bind_param('i', $user_id);
$rq->execute();
$recordings = $rq->get_result()->fetch_all(MYSQLI_ASSOC);
$rq->close();

$courses = [];
$cq = $conn->prepare("SELECT id, name, code, color FROM courses WHERE user_id=? ORDER BY code");
$cq->bind_param('i', $user_id);
$cq->execute();
$courses = $cq->get_result()->fetch_all(MYSQLI_ASSOC);
$cq->close();

function formatDuration(int $secs): string {
    return sprintf('%02d:%02d', intdiv($secs, 60), $secs % 60);
}
function formatSize(int $bytes): string {
    if ($bytes < 1024*1024) return round($bytes/1024, 1).' KB';
    return round($bytes/1024/1024, 1).' MB';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Lecture Recorder — NoteNest AI</title>
    <link rel="shortcut icon" href="img/fav.ico" type="image/x-icon">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --primary:#0b4954; --accent:#197f8f; --bg:#f0f4f8; --red:#e74c3c; }
        body { font-family:'Inter',sans-serif; background:var(--bg); }

        .page-header {
            background: linear-gradient(135deg,var(--primary),var(--accent));
            padding:28px 0 24px;color:#fff;margin-bottom:32px;
        }
        .page-header h1 { font-size:1.7rem;font-weight:700; }

        /* ── Recorder UI ── */
        .recorder-card {
            background:#fff;border-radius:20px;
            box-shadow:0 8px 40px rgba(0,0,0,.1);
            overflow:hidden;margin-bottom:28px;
        }
        .recorder-top {
            background:linear-gradient(135deg,#1a1a2e,#16213e,#0f3460);
            padding:40px;text-align:center;position:relative;
        }

        /* Waveform canvas */
        #waveCanvas {
            width:100%;height:80px;border-radius:10px;
            background:rgba(255,255,255,.05);display:block;
        }

        /* Timer */
        .rec-timer {
            font-size:3.5rem;font-weight:800;
            color:#fff;letter-spacing:4px;
            font-family:'Courier New',monospace;
            margin:20px 0 10px;
        }
        .rec-status {
            font-size:.85rem;color:rgba(255,255,255,.6);
            display:flex;align-items:center;justify-content:center;gap:8px;
            margin-bottom:28px;
        }
        .rec-dot { width:10px;height:10px;border-radius:50%;background:#e74c3c;flex-shrink:0; }
        .rec-dot.pulse { animation:pulse 1.2s infinite; }
        @keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.4;transform:scale(1.3)} }

        /* Control buttons */
        .rec-controls { display:flex;align-items:center;justify-content:center;gap:20px; }
        .btn-rec-main {
            width:72px;height:72px;border-radius:50%;border:none;cursor:pointer;
            font-size:1.4rem;transition:all .2s;display:flex;align-items:center;justify-content:center;
            box-shadow:0 4px 20px rgba(0,0,0,.3);
        }
        .btn-rec-main.start { background:#e74c3c;color:#fff; }
        .btn-rec-main.start:hover { background:#c0392b;transform:scale(1.08); }
        .btn-rec-main.stop  { background:#fff;color:#e74c3c; }
        .btn-rec-main.stop:hover  { background:#fdecea;transform:scale(1.08); }

        .btn-rec-sec {
            width:48px;height:48px;border-radius:50%;border:2px solid rgba(255,255,255,.3);
            background:rgba(255,255,255,.1);color:#fff;cursor:pointer;
            font-size:1rem;transition:all .2s;display:flex;align-items:center;justify-content:center;
        }
        .btn-rec-sec:hover { background:rgba(255,255,255,.2);border-color:rgba(255,255,255,.6); }
        .btn-rec-sec:disabled { opacity:.3;cursor:not-allowed; }

        /* Save form */
        .recorder-bottom { padding:24px 28px; }
        .save-form { display:none; }
        .save-form.visible { display:block; }

        /* Playback preview */
        #audioPreview { width:100%;margin:12px 0; display:none; }
        audio { border-radius:10px; }

        /* ── Recordings List ── */
        .panel-card {
            background:#fff;border-radius:16px;
            box-shadow:0 4px 20px rgba(0,0,0,.06);
            padding:24px;margin-bottom:24px;
        }
        .section-title { font-size:1rem;font-weight:700;color:var(--primary);margin-bottom:18px;display:flex;align-items:center;gap:8px; }

        .rec-item {
            border:1px solid #e8edf2;border-radius:12px;padding:16px;
            margin-bottom:12px;transition:box-shadow .2s;
        }
        .rec-item:hover { box-shadow:0 4px 16px rgba(0,0,0,.08); }
        .rec-title { font-weight:700;color:#2c3e50;font-size:.92rem;margin-bottom:4px; }
        .rec-meta  { font-size:.78rem;color:#888;display:flex;gap:14px;flex-wrap:wrap;margin-bottom:10px; }
        .rec-meta span { display:flex;align-items:center;gap:4px; }
        .course-dot { width:8px;height:8px;border-radius:50%;display:inline-block; }

        .waveform-mini {
            width:100%;height:36px;border-radius:6px;
            background:#f5f7fa;margin-bottom:10px;overflow:hidden;
        }

        .empty-state { text-align:center;padding:50px 20px;color:#bbb; }
        .empty-state i { font-size:48px;display:block;margin-bottom:12px;color:#ddd; }

        /* Toast */
        #toast {
            position:fixed;bottom:20px;right:20px;z-index:9999;
            background:#2c3e50;color:#fff;border-radius:10px;
            padding:10px 18px;font-size:.88rem;display:none;
            animation:slideInRight .3s ease;
        }
        @keyframes slideInRight { from{opacity:0;transform:translateX(30px)} to{opacity:1;transform:translateX(0)} }
    </style>
</head>
<body>

<?php include 'includes/navbar.php'; ?>

<!-- Page Header -->
<div class="page-header">
    <div class="container">
        <div class="d-flex align-items-center gap-3">
            <i class="fas fa-microphone fa-2x opacity-75"></i>
            <div>
                <h1 class="mb-1">Lecture Recorder</h1>
                <p class="mb-0" style="opacity:.8">Record, save & manage your classroom lectures</p>
            </div>
            <a href="dashboard.php" class="btn btn-outline-light ms-auto">
                <i class="fas fa-arrow-left me-1"></i> Dashboard
            </a>
        </div>
    </div>
</div>

<div class="container pb-5">
    <div class="row g-4">

        <!-- ── Recorder ── -->
        <div class="col-lg-7">
            <div class="recorder-card">
                <!-- Dark Top Section -->
                <div class="recorder-top">
                    <canvas id="waveCanvas"></canvas>
                    <div class="rec-timer" id="recTimer">00:00</div>
                    <div class="rec-status">
                        <div class="rec-dot" id="recDot"></div>
                        <span id="recStatusText">Ready to record</span>
                    </div>
                    <div class="rec-controls">
                        <!-- Pause button -->
                        <button class="btn-rec-sec" id="btnPause" disabled title="Pause">
                            <i class="fas fa-pause"></i>
                        </button>
                        <!-- Main record/stop button -->
                        <button class="btn-rec-main start" id="btnRecord" title="Start Recording">
                            <i class="fas fa-microphone" id="recBtnIcon"></i>
                        </button>
                        <!-- Discard button -->
                        <button class="btn-rec-sec" id="btnDiscard" disabled title="Discard">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>

                <!-- Save Form -->
                <div class="recorder-bottom">
                    <!-- Playback preview (shows after recording) -->
                    <audio id="audioPreview" controls></audio>

                    <div class="save-form" id="saveForm">
                        <h6 style="font-weight:700;color:var(--primary);margin-bottom:14px;">
                            <i class="fas fa-save me-1"></i>Save Recording
                        </h6>
                        <div class="mb-3">
                            <label class="form-label" style="font-size:.84rem;font-weight:600;">Recording Title</label>
                            <input type="text" id="recTitle" class="form-control" placeholder="e.g. CS412 - Lecture 5: Algorithms"
                                   style="border-radius:10px;">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" style="font-size:.84rem;font-weight:600;">Course (optional)</label>
                            <select id="recCourse" class="form-select" style="border-radius:10px;">
                                <option value="0">No course</option>
                                <?php foreach ($courses as $c): ?>
                                <option value="<?php echo $c['id']; ?>">
                                    <?php echo htmlspecialchars($c['code'].' — '.$c['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button id="btnSave" class="btn w-100 py-2" style="background:linear-gradient(135deg,#0b4954,#197f8f);color:#fff;border:none;border-radius:10px;font-weight:600;">
                            <i class="fas fa-cloud-upload-alt me-1"></i> Save Recording
                        </button>
                        <button id="btnNewRec" class="btn btn-outline-secondary w-100 mt-2" style="border-radius:10px;font-size:.88rem;">
                            <i class="fas fa-redo me-1"></i> Record Again
                        </button>
                    </div>

                    <!-- Instructions -->
                    <div id="recInstructions" style="text-align:center;padding:8px 0;">
                        <p style="color:#aaa;font-size:.84rem;margin:0;">
                            <i class="fas fa-info-circle me-1"></i>
                            Click the <strong style="color:var(--accent)">microphone</strong> button to start recording.
                            Your browser will ask for microphone permission.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Tips Card -->
            <div class="panel-card">
                <div class="section-title"><i class="fas fa-lightbulb text-warning"></i> Recording Tips</div>
                <div class="row g-3">
                    <?php
                    $tips = [
                        ['🎤','Speak clearly','Hold the mic close and speak at a consistent volume'],
                        ['🔇','Minimize noise','Close windows, fans, and choose a quiet environment'],
                        ['⏸️','Use Pause','Pause during breaks to keep recordings concise'],
                        ['📝','Take notes','Use AI Tutor after to clarify anything you missed'],
                    ];
                    foreach ($tips as $t): ?>
                    <div class="col-sm-6">
                        <div style="background:#f8fafb;border-radius:10px;padding:12px;">
                            <div style="font-size:1.3rem;margin-bottom:6px;"><?php echo $t[0]; ?></div>
                            <div style="font-size:.83rem;font-weight:700;color:#2c3e50;margin-bottom:3px;"><?php echo $t[1]; ?></div>
                            <div style="font-size:.78rem;color:#888;"><?php echo $t[2]; ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ── Recordings List ── -->
        <div class="col-lg-5">
            <div class="panel-card">
                <div class="section-title"><i class="fas fa-list"></i> My Recordings
                    <span class="badge ms-auto" style="background:#f0f7f9;color:var(--accent);">
                        <?php echo count($recordings); ?>
                    </span>
                </div>

                <?php if (empty($recordings)): ?>
                <div class="empty-state">
                    <i class="fas fa-microphone-slash"></i>
                    <p>No recordings yet.<br>Record your first lecture above!</p>
                </div>
                <?php else: ?>
                <div id="recordingsList">
                <?php foreach ($recordings as $rec): ?>
                <div class="rec-item" id="rec-<?php echo $rec['id']; ?>">
                    <div class="rec-title" id="rt-<?php echo $rec['id']; ?>">
                        <?php echo htmlspecialchars($rec['title']); ?>
                    </div>
                    <div class="rec-meta">
                        <span><i class="fas fa-clock"></i><?php echo formatDuration($rec['duration_sec']); ?></span>
                        <span><i class="fas fa-file"></i><?php echo formatSize($rec['file_size']); ?></span>
                        <span><i class="fas fa-calendar"></i><?php echo date('d M Y', strtotime($rec['created_at'])); ?></span>
                        <?php if ($rec['course_name']): ?>
                        <span>
                            <span class="course-dot" style="background:<?php echo htmlspecialchars($rec['course_color']); ?>;"></span>
                            <?php echo htmlspecialchars($rec['course_name']); ?>
                        </span>
                        <?php endif; ?>
                    </div>

                    <!-- Playback -->
                    <audio controls style="width:100%;height:32px;border-radius:6px;margin-bottom:8px;"
                           src="<?php echo htmlspecialchars($rec['file_path']); ?>"></audio>

                    <!-- Actions -->
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm flex-fill"
                                style="background:#f0f7f9;color:var(--accent);border:none;border-radius:8px;font-size:.78rem;font-weight:600;"
                                onclick="editTitle(<?php echo $rec['id']; ?>)">
                            <i class="fas fa-pen me-1"></i>Rename
                        </button>
                        <a href="<?php echo htmlspecialchars($rec['file_path']); ?>" download
                           class="btn btn-sm"
                           style="background:#eafaf1;color:#27ae60;border:none;border-radius:8px;font-size:.78rem;font-weight:600;">
                            <i class="fas fa-download me-1"></i>Save
                        </a>
                        <button class="btn btn-sm"
                                style="background:#fdecea;color:#e74c3c;border:none;border-radius:8px;font-size:.78rem;font-weight:600;"
                                onclick="deleteRec(<?php echo $rec['id']; ?>)">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div id="toast"></div>

<script>
// ============================================================
// Web Audio Recorder — using MediaRecorder API
// ============================================================
let mediaRecorder, audioChunks = [], audioBlob;
let timerInterval, seconds = 0, isPaused = false;
let audioContext, analyser, animFrame;

// ── Timer helpers ─────────────────────────────────────────────
function startTimer() {
    timerInterval = setInterval(() => {
        if (!isPaused) {
            seconds++;
            const m = String(Math.floor(seconds/60)).padStart(2,'0');
            const s = String(seconds%60).padStart(2,'0');
            document.getElementById('recTimer').textContent = m+':'+s;
        }
    }, 1000);
}
function stopTimer()  { clearInterval(timerInterval); }
function resetTimer() { seconds=0; document.getElementById('recTimer').textContent='00:00'; }

// ── Waveform visualiser ───────────────────────────────────────
const canvas = document.getElementById('waveCanvas');
const ctx2d  = canvas.getContext('2d');

function startVisualiser(stream) {
    audioContext = new (window.AudioContext || window.webkitAudioContext)();
    analyser = audioContext.createAnalyser();
    analyser.fftSize = 256;
    const src = audioContext.createMediaStreamSource(stream);
    src.connect(analyser);
    drawWave();
}

function drawWave() {
    animFrame = requestAnimationFrame(drawWave);
    const W = canvas.width  = canvas.offsetWidth;
    const H = canvas.height = canvas.offsetHeight;
    const data = new Uint8Array(analyser.frequencyBinCount);
    analyser.getByteFrequencyData(data);

    ctx2d.clearRect(0,0,W,H);
    const barW = (W / data.length) * 2.5;
    let x = 0;
    data.forEach(v => {
        const h = (v/255)*H;
        const alpha = isPaused ? .3 : .8;
        ctx2d.fillStyle = `rgba(25,127,143,${alpha})`;
        ctx2d.fillRect(x, H-h, barW-1, h);
        x += barW;
    });
}

function stopVisualiser() {
    cancelAnimationFrame(animFrame);
    if (audioContext) audioContext.close();
    ctx2d.clearRect(0,0,canvas.offsetWidth, canvas.offsetHeight);
}

// ── Start recording ───────────────────────────────────────────
document.getElementById('btnRecord').addEventListener('click', async function() {
    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
        // STOP
        mediaRecorder.stop();
        return;
    }

    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio:true });
        audioChunks  = [];
        mediaRecorder = new MediaRecorder(stream, { mimeType:'audio/webm' });

        mediaRecorder.ondataavailable = e => { if (e.data.size>0) audioChunks.push(e.data); };

        mediaRecorder.onstop = () => {
            audioBlob = new Blob(audioChunks, { type:'audio/webm' });
            const url = URL.createObjectURL(audioBlob);
            const preview = document.getElementById('audioPreview');
            preview.src = url;
            preview.style.display = 'block';
            stopVisualiser();
            stream.getTracks().forEach(t => t.stop());
            stopTimer();
            setUIState('stopped');
        };

        mediaRecorder.start(100);
        startVisualiser(stream);
        resetTimer();
        startTimer();
        setUIState('recording');

    } catch (err) {
        alert('Microphone access denied or not available.\n\nError: ' + err.message);
    }
});

// ── Pause / Resume ────────────────────────────────────────────
document.getElementById('btnPause').addEventListener('click', function() {
    if (!mediaRecorder) return;
    if (mediaRecorder.state === 'recording') {
        mediaRecorder.pause();
        isPaused = true;
        document.getElementById('btnPause').innerHTML = '<i class="fas fa-play"></i>';
        document.getElementById('recStatusText').textContent = 'Paused';
        document.getElementById('recDot').classList.remove('pulse');
    } else if (mediaRecorder.state === 'paused') {
        mediaRecorder.resume();
        isPaused = false;
        document.getElementById('btnPause').innerHTML = '<i class="fas fa-pause"></i>';
        document.getElementById('recStatusText').textContent = 'Recording...';
        document.getElementById('recDot').classList.add('pulse');
    }
});

// ── Discard ───────────────────────────────────────────────────
document.getElementById('btnDiscard').addEventListener('click', function() {
    if (!confirm('Discard this recording?')) return;
    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
        mediaRecorder.stop();
    }
    audioBlob = null; audioChunks = [];
    document.getElementById('audioPreview').style.display = 'none';
    document.getElementById('saveForm').classList.remove('visible');
    resetTimer();
    stopTimer();
    setUIState('idle');
    toast('Recording discarded.');
});

// ── UI State machine ──────────────────────────────────────────
function setUIState(state) {
    const btnRec  = document.getElementById('btnRecord');
    const btnIcon = document.getElementById('recBtnIcon');
    const btnPause= document.getElementById('btnPause');
    const btnDisc = document.getElementById('btnDiscard');
    const dot     = document.getElementById('recDot');
    const status  = document.getElementById('recStatusText');
    const instr   = document.getElementById('recInstructions');
    const sform   = document.getElementById('saveForm');

    if (state === 'recording') {
        btnRec.className  = 'btn-rec-main stop';
        btnIcon.className = 'fas fa-stop';
        btnRec.title      = 'Stop Recording';
        btnPause.disabled = false;
        btnDisc.disabled  = false;
        dot.classList.add('pulse');
        status.textContent = 'Recording...';
        instr.style.display = 'none';
        sform.classList.remove('visible');
    } else if (state === 'stopped') {
        btnRec.className  = 'btn-rec-main start';
        btnIcon.className = 'fas fa-microphone';
        btnRec.title      = 'Start Recording';
        btnPause.disabled = true;
        btnDisc.disabled  = true;
        dot.classList.remove('pulse');
        status.textContent = 'Recording complete';
        instr.style.display = 'none';
        sform.classList.add('visible');
        // Default title
        document.getElementById('recTitle').value = 'Lecture ' + new Date().toLocaleDateString('en',{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});
    } else { // idle
        btnRec.className  = 'btn-rec-main start';
        btnIcon.className = 'fas fa-microphone';
        btnPause.disabled = true;
        btnDisc.disabled  = true;
        dot.classList.remove('pulse');
        status.textContent = 'Ready to record';
        instr.style.display = 'block';
        sform.classList.remove('visible');
    }
}

// ── Save ──────────────────────────────────────────────────────
document.getElementById('btnSave').addEventListener('click', async function() {
    if (!audioBlob) { alert('Nothing to save.'); return; }
    const title    = document.getElementById('recTitle').value.trim() || 'Untitled Recording';
    const courseId = document.getElementById('recCourse').value;

    this.disabled = true;
    this.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Saving...';

    const formData = new FormData();
    formData.append('action',    'save_recording');
    formData.append('audio',     audioBlob, 'recording.webm');
    formData.append('title',     title);
    formData.append('course_id', courseId);
    formData.append('duration',  seconds);

    try {
        const res  = await fetch('lecture_recorder.php', { method:'POST', body:formData });
        const data = await res.json();
        if (data.success) {
            toast('✅ Recording saved!');
            setTimeout(() => location.reload(), 800);
        } else {
            alert('Save failed: ' + (data.error||'Unknown error'));
            this.disabled = false;
            this.innerHTML = '<i class="fas fa-cloud-upload-alt me-1"></i> Save Recording';
        }
    } catch(e) {
        alert('Network error.'); this.disabled=false;
        this.innerHTML = '<i class="fas fa-cloud-upload-alt me-1"></i> Save Recording';
    }
});

// ── Record Again ──────────────────────────────────────────────
document.getElementById('btnNewRec').addEventListener('click', function() {
    audioBlob = null; audioChunks = [];
    document.getElementById('audioPreview').style.display='none';
    resetTimer(); stopTimer();
    setUIState('idle');
    isPaused = false;
});

// ── Edit title ────────────────────────────────────────────────
function editTitle(id) {
    const el     = document.getElementById('rt-'+id);
    const oldVal = el.textContent.trim();
    const newVal = prompt('Rename recording:', oldVal);
    if (!newVal || newVal === oldVal) return;

    fetch('lecture_recorder.php', {
        method:'POST',
        body: new URLSearchParams({ action:'update_title', rec_id:id, title:newVal })
    }).then(r=>r.json()).then(d => {
        if (d.success) { el.textContent = newVal; toast('✅ Renamed!'); }
    });
}

// ── Delete recording ──────────────────────────────────────────
function deleteRec(id) {
    if (!confirm('Delete this recording permanently?')) return;
    fetch('lecture_recorder.php', {
        method:'POST',
        body: new URLSearchParams({ action:'delete_recording', rec_id:id })
    }).then(r=>r.json()).then(d => {
        if (d.success) {
            document.getElementById('rec-'+id).style.opacity='0';
            setTimeout(()=>document.getElementById('rec-'+id).remove(), 300);
            toast('Recording deleted.');
        }
    });
}

// ── Toast ─────────────────────────────────────────────────────
function toast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.style.display='block';
    setTimeout(()=>t.style.display='none', 2500);
}

// Init idle state
setUIState('idle');
</script>
</body>
</html>

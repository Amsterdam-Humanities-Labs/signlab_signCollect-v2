export class VideoRecorder {
  constructor(videoEl) {
    this.video = videoEl;
    this.stream = null;
    this.recorder = null;
    this.chunks = [];
    this.startTime = 0;
    this.timerInterval = null;
  }

  async start(onTick) {
    this.stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
    this.video.srcObject = this.stream;
    await this.video.play().catch(() => {});

    const mimeCandidates = ['video/webm;codecs=vp9', 'video/webm;codecs=vp8', 'video/webm'];
    const mime = mimeCandidates.find(t => MediaRecorder.isTypeSupported(t)) || '';
    this.recorder = new MediaRecorder(this.stream, mime ? { mimeType: mime } : undefined);
    this.chunks = [];
    this.recorder.addEventListener('dataavailable', e => {
      if (e.data && e.data.size > 0) this.chunks.push(e.data);
    });
    this.recorder.start();
    this.startTime = Date.now();
    if (onTick) {
      this.timerInterval = setInterval(() => onTick(Math.floor((Date.now() - this.startTime) / 1000)), 250);
    }
  }

  async stop() {
    if (this.timerInterval) { clearInterval(this.timerInterval); this.timerInterval = null; }
    if (!this.recorder) return null;
    const blob = await new Promise(resolve => {
      this.recorder.addEventListener('stop', () => {
        resolve(new Blob(this.chunks, { type: this.recorder.mimeType || 'video/webm' }));
      }, { once: true });
      this.recorder.stop();
    });
    this.cleanup();
    return blob;
  }

  cleanup() {
    if (this.stream) {
      this.stream.getTracks().forEach(t => t.stop());
      this.stream = null;
    }
    if (this.video) this.video.srcObject = null;
    this.recorder = null;
    this.chunks = [];
  }
}

export function fmtTime(sec) {
  const m = Math.floor(sec / 60);
  const s = sec % 60;
  return `${m}:${s.toString().padStart(2, '0')}`;
}

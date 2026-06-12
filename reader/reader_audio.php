<?php
// ============================================================
//  READER_AUDIO.PHP – Audio-only mode for the reader
// ============================================================
?>
<script>
class ReaderAudio {
    constructor() {
        this.synth = window.speechSynthesis;
        this.currentUtterance = null;
        this.isPlaying = false;
        this.isPaused = false;
        this.currentParagraph = 0;
        this.paragraphs = [];
        this.rate = 1.0;
        this.pitch = 1.0;
        this.voice = null;
    }

    // Load paragraphs from the reader
    loadParagraphs() {
        const elements = document.querySelectorAll('.aw-reader-text p');
        this.paragraphs = [];
        elements.forEach((el, index) => {
            const text = el.textContent.trim();
            if (text.length > 0) {
                this.paragraphs.push({
                    index: index,
                    text: text,
                    element: el
                });
            }
        });
        return this.paragraphs.length;
    }

    // Start audio-only mode
    start() {
        if (this.paragraphs.length === 0) {
            this.loadParagraphs();
        }
        this.currentParagraph = 0;
        this.playParagraph();
        this.showAudioControls();
    }

    // Play a specific paragraph
    playParagraph(index = null) {
        if (index !== null) {
            this.currentParagraph = index;
        }

        if (this.currentParagraph >= this.paragraphs.length) {
            this.stop();
            alert('🎉 You have finished the book in audio mode!');
            return;
        }

        const paragraph = this.paragraphs[this.currentParagraph];
        if (!paragraph) return;

        // Highlight current paragraph
        this.highlightParagraph(paragraph.index);

        const text = paragraph.text;
        const utterance = new SpeechSynthesisUtterance(text);
        utterance.lang = 'en-US';
        utterance.rate = this.rate;
        utterance.pitch = this.pitch;

        if (this.voice) {
            utterance.voice = this.voice;
        }

        utterance.onend = () => {
            this.currentParagraph++;
            this.playParagraph();
        };

        utterance.onerror = () => {
            this.stop();
        };

        this.currentUtterance = utterance;
        this.synth.speak(utterance);
        this.isPlaying = true;
        this.isPaused = false;
    }

    // Pause
    pause() {
        if (this.isPlaying && !this.isPaused) {
            this.synth.pause();
            this.isPaused = true;
        }
    }

    // Resume
    resume() {
        if (this.isPaused) {
            this.synth.resume();
            this.isPaused = false;
        }
    }

    // Stop
    stop() {
        this.synth.cancel();
        this.isPlaying = false;
        this.isPaused = false;
        this.currentUtterance = null;
        this.hideAudioControls();
        this.clearHighlights();
    }

    // Highlight the current paragraph
    highlightParagraph(index) {
        this.clearHighlights();
        const paragraph = this.paragraphs.find(p => p.index === index);
        if (paragraph && paragraph.element) {
            paragraph.element.style.background = 'rgba(219,161,162,0.2)';
            paragraph.element.style.padding = '4px 8px';
            paragraph.element.style.borderRadius = '4px';
            paragraph.element.style.transition = 'all 0.3s';
        }
    }

    // Clear all highlights
    clearHighlights() {
        this.paragraphs.forEach(p => {
            if (p.element) {
                p.element.style.background = '';
                p.element.style.padding = '';
                p.element.style.borderRadius = '';
            }
        });
    }

    // Show audio controls
    showAudioControls() {
        const controls = document.getElementById('awAudioControls');
        if (controls) {
            controls.style.display = 'flex';
        }
        // Hide reader content, keep only text
        const content = document.getElementById('awReaderText');
        if (content) {
            content.style.opacity = '0.5';
        }
    }

    // Hide audio controls
    hideAudioControls() {
        const controls = document.getElementById('awAudioControls');
        if (controls) {
            controls.style.display = 'none';
        }
        const content = document.getElementById('awReaderText');
        if (content) {
            content.style.opacity = '1';
        }
    }

    // Set speed
    setRate(rate) {
        this.rate = rate;
        if (this.isPlaying) {
            // Restart current paragraph with new rate
            this.synth.cancel();
            this.playParagraph(this.currentParagraph);
        }
    }

    // Set pitch
    setPitch(pitch) {
        this.pitch = pitch;
    }

    // Select voice
    setVoiceByName(name) {
        const voices = this.synth.getVoices();
        const voice = voices.find(v => v.name.includes(name));
        if (voice) {
            this.voice = voice;
        }
    }

    // Jump to a specific paragraph
    jumpToParagraph(index) {
        if (index >= 0 && index < this.paragraphs.length) {
            this.synth.cancel();
            this.currentParagraph = index;
            this.playParagraph();
        }
    }
}

// Initialize audio
const readerAudio = new ReaderAudio();

// Attach to button in reader header
document.getElementById('awAudioModeBtn')?.addEventListener('click', function() {
    if (readerAudio.isPlaying) {
        readerAudio.stop();
    } else {
        readerAudio.start();
    }
});

// Audio controls
document.getElementById('awAudioPauseBtn')?.addEventListener('click', function() {
    if (readerAudio.isPaused) {
        readerAudio.resume();
        this.innerHTML = '<i class="fas fa-pause"></i>';
    } else {
        readerAudio.pause();
        this.innerHTML = '<i class="fas fa-play"></i>';
    }
});

document.getElementById('awAudioStopBtn')?.addEventListener('click', function() {
    readerAudio.stop();
});

document.getElementById('awAudioNextBtn')?.addEventListener('click', function() {
    readerAudio.synth.cancel();
    readerAudio.currentParagraph++;
    readerAudio.playParagraph();
});

document.getElementById('awAudioPrevBtn')?.addEventListener('click', function() {
    readerAudio.synth.cancel();
    readerAudio.currentParagraph = Math.max(0, readerAudio.currentParagraph - 1);
    readerAudio.playParagraph();
});

document.getElementById('awAudioSpeed')?.addEventListener('change', function() {
    readerAudio.setRate(parseFloat(this.value));
});

document.getElementById('awAudioVoice')?.addEventListener('change', function() {
    readerAudio.setVoiceByName(this.value);
});
</script>
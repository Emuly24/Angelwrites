<?php
// ============================================================
//  READER_TTS.PHP – Text‑to‑Speech engine for the reader
//  Uses the Web Speech API with real‑time word highlighting.
// ============================================================
?>
<script>
class ReaderTTS {
    constructor() {
        this.synth = window.speechSynthesis;
        this.currentUtterance = null;
        this.isPlaying = false;
        this.rate = 1.0;
        this.pitch = 1.0;
        this.voice = null;
        this.highlightClass = 'tts-highlight';
    }

    // Load available voices
    loadVoices() {
        return new Promise((resolve) => {
            let voices = this.synth.getVoices();
            if (voices.length) {
                resolve(voices);
            } else {
                this.synth.onvoiceschanged = () => {
                    voices = this.synth.getVoices();
                    resolve(voices);
                };
            }
        });
    }

    // Speak a paragraph (or a range of text)
    speak(text, lang = 'en-US', wordCallback = null) {
        if (this.isPlaying) {
            this.stop();
        }

        const utterance = new SpeechSynthesisUtterance(text);
        utterance.lang = lang;
        utterance.rate = this.rate;
        utterance.pitch = this.pitch;

        if (this.voice) {
            utterance.voice = this.voice;
        }

        // Real‑time word highlighting (if callback provided)
        if (wordCallback) {
            utterance.onboundary = (event) => {
                if (event.name === 'word') {
                    const charIndex = event.charIndex;
                    const wordLength = event.charLength;
                    wordCallback(charIndex, charIndex + wordLength);
                }
            };
        }

        utterance.onend = () => {
            this.isPlaying = false;
            if (wordCallback) {
                wordCallback(-1, -1); // Clear highlight
            }
        };

        utterance.onerror = () => {
            this.isPlaying = false;
        };

        this.currentUtterance = utterance;
        this.synth.speak(utterance);
        this.isPlaying = true;
    }

    // Stop current playback
    stop() {
        this.synth.cancel();
        this.isPlaying = false;
        this.currentUtterance = null;
    }

    // Pause
    pause() {
        if (this.isPlaying) {
            this.synth.pause();
        }
    }

    // Resume
    resume() {
        if (this.isPlaying && this.synth.paused) {
            this.synth.resume();
        }
    }

    // Set speed (0.5 – 2.0)
    setRate(rate) {
        this.rate = rate;
    }

    // Set pitch (0.5 – 2.0)
    setPitch(pitch) {
        this.pitch = pitch;
    }

    // Select a voice by name
    setVoiceByName(name) {
        this.loadVoices().then(voices => {
            const voice = voices.find(v => v.name.includes(name));
            if (voice) {
                this.voice = voice;
            }
        });
    }
}

// Initialize TTS engine
const tts = new ReaderTTS();

// Example: Attach to a button in your reader header
document.getElementById('awTtsPlayBtn')?.addEventListener('click', () => {
    const paragraph = document.querySelector('.aw-reader-text p')?.textContent;
    if (paragraph) {
        tts.speak(paragraph, 'en-US', (start, end) => {
            // Highlight words in the paragraph
            // Implementation depends on your DOM structure
            // We'll add a simple example below
        });
    }
});

document.getElementById('awTtsStopBtn')?.addEventListener('click', () => {
    tts.stop();
});
</script>
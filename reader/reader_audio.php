<?php
// ============================================================
//  READER_AUDIO.PHP – Enhanced Audio Mode for the Reader
// ============================================================
?>
<style>
#awAudioControls {
    display: none;
    position: fixed;
    bottom: 20px;
    left: 50%;
    transform: translateX(-50%);
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 12px 20px;
    box-shadow: var(--shadow-hover);
    z-index: 10000;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    justify-content: center;
    backdrop-filter: blur(8px);
    min-width: 300px;
}
#awAudioControls .controls-row {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
#awAudioControls button {
    background: var(--rose);
    border: none;
    color: white;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    cursor: pointer;
    font-size: 1.2rem;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
}
#awAudioControls button:hover {
    transform: scale(1.05);
    background: var(--rose-dark);
}
#awAudioControls button.secondary {
    background: var(--border);
    color: var(--text);
}
#awAudioControls button.secondary:hover {
    background: var(--text-light);
    color: white;
}
#awAudioControls .progress-bar {
    flex: 1;
    min-width: 120px;
    height: 6px;
    background: var(--border);
    border-radius: 4px;
    overflow: hidden;
    position: relative;
}
#awAudioControls .progress-bar .fill {
    height: 100%;
    background: var(--rose);
    width: 0%;
    transition: width 0.3s;
}
#awAudioControls .info {
    font-size: 0.8rem;
    color: var(--text-light);
    white-space: nowrap;
}
#awAudioControls select {
    padding: 4px 8px;
    border-radius: 6px;
    border: 1px solid var(--border);
    background: var(--input-bg);
    color: var(--text);
    font-size: 0.8rem;
}
#awAudioControls input[type="range"] {
    width: 80px;
    accent-color: var(--rose);
}
.aw-audio-highlight {
    background: rgba(219, 161, 162, 0.25) !important;
    padding: 2px 6px !important;
    border-radius: 4px !important;
    transition: all 0.3s;
}
</style>

<div id="awAudioControls">
    <div class="controls-row">
        <button id="awAudioPrevBtn" class="secondary" title="Previous paragraph"><i class="fas fa-step-backward"></i></button>
        <button id="awAudioPlayBtn" title="Play/Pause"><i class="fas fa-play"></i></button>
        <button id="awAudioStopBtn" class="secondary" title="Stop"><i class="fas fa-stop"></i></button>
        <button id="awAudioNextBtn" class="secondary" title="Next paragraph"><i class="fas fa-step-forward"></i></button>
    </div>
    <div class="controls-row">
        <span class="info" id="awAudioProgress">0 / 0</span>
        <div class="progress-bar">
            <div class="fill" id="awAudioProgressFill"></div>
        </div>
        <span class="info" id="awAudioSpeedLabel">1.0x</span>
        <input type="range" id="awAudioSpeed" min="0.5" max="2.0" step="0.1" value="1.0">
        <select id="awAudioVoice"></select>
    </div>
</div>

<script>
class ReaderAudio {
    constructor() {
        this.synth = window.speechSynthesis;
        this.currentUtterance = null;
        this.isPlaying = false;
        this.isPaused = false;
        this.currentParagraph = 0;
        this.paragraphs = [];
        this.rate = parseFloat(localStorage.getItem('readerAudioRate') || '1.0');
        this.pitch = parseFloat(localStorage.getItem('readerAudioPitch') || '1.0');
        this.voiceName = localStorage.getItem('readerAudioVoice') || '';
        this.voice = null;
        this.pageContent = null;
        this.currentPage = 1;
        this.totalPages = 1;
        this.isLoadingNextPage = false;
    }

    // --- Initialize voices ---
    loadVoices() {
        const select = document.getElementById('awAudioVoice');
        const voices = this.synth.getVoices();
        select.innerHTML = '';
        voices.forEach(v => {
            const opt = document.createElement('option');
            opt.value = v.name;
            opt.textContent = v.name + (v.default ? ' (default)' : '');
            if (v.name === this.voiceName) opt.selected = true;
            select.appendChild(opt);
        });
        if (this.voiceName) {
            this.voice = voices.find(v => v.name === this.voiceName) || null;
        }
    }

    // --- Get paragraphs from current page ---
    loadParagraphs() {
        // Detect the current page content container
        const container = document.querySelector('.page-content-inner[data-page="' + this.currentPage + '"]') ||
                          document.querySelector('#scroll-container .page-content-inner') ||
                          document.querySelector('#flip-container .flip-page-custom') ||
                          document.body;
        this.pageContent = container;

        // Find all paragraphs (p, h1, h2, h3, li, etc.)
        const elements = container.querySelectorAll('p, h1, h2, h3, h4, h5, h6, li');
        this.paragraphs = [];
        elements.forEach((el, index) => {
            const text = el.textContent.trim();
            if (text.length > 10) { // skip short texts like page numbers
                this.paragraphs.push({
                    index: index,
                    text: text,
                    element: el
                });
            }
        });

        // If no paragraphs found, fallback to all text nodes
        if (this.paragraphs.length === 0) {
            const walker = document.createTreeWalker(container, NodeFilter.SHOW_TEXT, null, false);
            let node;
            let idx = 0;
            while (node = walker.nextNode()) {
                const text = node.textContent.trim();
                if (text.length > 10) {
                    this.paragraphs.push({
                        index: idx++,
                        text: text,
                        element: node.parentElement
                    });
                }
            }
        }

        return this.paragraphs.length;
    }

    // --- Start audio mode ---
    start() {
        if (!this.synth) {
            alert('Your browser does not support text-to-speech.');
            return;
        }
        this.loadVoices();
        this.currentPage = parseInt(document.getElementById('pageNum')?.textContent) || 1;
        this.totalPages = parseInt(document.getElementById('totalPages')?.textContent) || 1;
        if (this.paragraphs.length === 0) {
            this.loadParagraphs();
        }
        if (this.paragraphs.length === 0) {
            alert('No readable content found on this page.');
            return;
        }
        this.currentParagraph = 0;
        this.playParagraph();
        this.showControls();
    }

    // --- Play current paragraph ---
    playParagraph(index = null) {
        if (index !== null) {
            this.currentParagraph = index;
        }

        // Check if we've finished all paragraphs on this page
        if (this.currentParagraph >= this.paragraphs.length) {
            // Try to load next page
            if (this.currentPage < this.totalPages) {
                this.loadNextPage();
                return;
            } else {
                this.stop();
                alert('🎉 You have finished the entire book in audio mode!');
                return;
            }
        }

        const paragraph = this.paragraphs[this.currentParagraph];
        if (!paragraph) {
            this.currentParagraph++;
            this.playParagraph();
            return;
        }

        this.highlightParagraph(paragraph.index);
        this.updateProgress();

        const text = paragraph.text;
        // Clean text (remove special characters that might confuse TTS)
        const cleanText = text.replace(/[^a-zA-Z0-9\s\.\,\!\?\-\'\"]/g, '');
        if (cleanText.length < 2) {
            this.currentParagraph++;
            this.playParagraph();
            return;
        }

        const utterance = new SpeechSynthesisUtterance(cleanText);
        utterance.lang = 'en-US';
        utterance.rate = this.rate;
        utterance.pitch = this.pitch;
        if (this.voice) utterance.voice = this.voice;

        utterance.onstart = () => {
            this.isPlaying = true;
            this.isPaused = false;
            this.updatePlayButton();
        };

        utterance.onend = () => {
            this.currentParagraph++;
            this.playParagraph();
        };

        utterance.onerror = (event) => {
            if (event.error !== 'canceled') {
                console.warn('TTS error:', event.error);
                this.stop();
            }
        };

        this.currentUtterance = utterance;
        this.synth.speak(utterance);
    }

    // --- Pause ---
    pause() {
        if (this.isPlaying && !this.isPaused) {
            this.synth.pause();
            this.isPaused = true;
            this.updatePlayButton();
        }
    }

    // --- Resume ---
    resume() {
        if (this.isPaused) {
            this.synth.resume();
            this.isPaused = false;
            this.updatePlayButton();
        }
    }

    // --- Stop ---
    stop() {
        this.synth.cancel();
        this.isPlaying = false;
        this.isPaused = false;
        this.currentUtterance = null;
        this.clearHighlights();
        this.hideControls();
        this.updatePlayButton();
    }

    // --- Toggle play/pause ---
    togglePlay() {
        if (this.isPaused) {
            this.resume();
        } else if (this.isPlaying) {
            this.pause();
        } else {
            this.start();
        }
    }

    // --- Highlight current paragraph ---
    highlightParagraph(index) {
        this.clearHighlights();
        const paragraph = this.paragraphs.find(p => p.index === index);
        if (paragraph && paragraph.element) {
            paragraph.element.classList.add('aw-audio-highlight');
        }
    }

    clearHighlights() {
        document.querySelectorAll('.aw-audio-highlight').forEach(el => {
            el.classList.remove('aw-audio-highlight');
        });
    }

    // --- Update progress UI ---
    updateProgress() {
        const total = this.paragraphs.length;
        const current = this.currentParagraph + 1;
        document.getElementById('awAudioProgress').textContent = current + ' / ' + total;
        const pct = (current / total) * 100;
        document.getElementById('awAudioProgressFill').style.width = pct + '%';
    }

    // --- Update play button icon ---
    updatePlayButton() {
        const btn = document.getElementById('awAudioPlayBtn');
        if (this.isPaused) {
            btn.innerHTML = '<i class="fas fa-play"></i>';
        } else if (this.isPlaying) {
            btn.innerHTML = '<i class="fas fa-pause"></i>';
        } else {
            btn.innerHTML = '<i class="fas fa-play"></i>';
        }
    }

    // --- Show audio controls ---
    showControls() {
        const controls = document.getElementById('awAudioControls');
        if (controls) {
            controls.style.display = 'flex';
        }
        // Dim the page content
        const content = document.getElementById('page-viewport');
        if (content) {
            content.style.opacity = '0.8';
            content.style.transition = 'opacity 0.3s';
        }
        this.updatePlayButton();
        this.updateProgress();
    }

    hideControls() {
        const controls = document.getElementById('awAudioControls');
        if (controls) {
            controls.style.display = 'none';
        }
        const content = document.getElementById('page-viewport');
        if (content) {
            content.style.opacity = '1';
        }
        this.clearHighlights();
    }

    // --- Load next page ---
    loadNextPage() {
        if (this.isLoadingNextPage) return;
        this.isLoadingNextPage = true;
        this.synth.cancel();

        const nextPage = this.currentPage + 1;
        // Use goToPage function if available
        if (typeof goToPage === 'function') {
            goToPage(nextPage);
            // Wait for page to load
            setTimeout(() => {
                this.currentPage = nextPage;
                this.loadParagraphs();
                this.currentParagraph = 0;
                this.playParagraph();
                this.isLoadingNextPage = false;
            }, 500);
        } else {
            // Fallback: just stop
            this.stop();
            alert('Please turn to page ' + nextPage + ' manually.');
            this.isLoadingNextPage = false;
        }
    }

    // --- Set speed ---
    setRate(rate) {
        this.rate = rate;
        localStorage.setItem('readerAudioRate', rate);
        if (this.isPlaying) {
            this.synth.cancel();
            this.playParagraph(this.currentParagraph);
        }
        document.getElementById('awAudioSpeedLabel').textContent = rate.toFixed(1) + 'x';
    }

    // --- Set pitch ---
    setPitch(pitch) {
        this.pitch = pitch;
        localStorage.setItem('readerAudioPitch', pitch);
    }

    // --- Set voice ---
    setVoiceByName(name) {
        this.voiceName = name;
        localStorage.setItem('readerAudioVoice', name);
        const voices = this.synth.getVoices();
        this.voice = voices.find(v => v.name === name) || null;
        if (this.isPlaying) {
            this.synth.cancel();
            this.playParagraph(this.currentParagraph);
        }
    }

    // --- Jump to paragraph ---
    jumpToParagraph(index) {
        if (index >= 0 && index < this.paragraphs.length) {
            this.synth.cancel();
            this.currentParagraph = index;
            this.playParagraph();
        }
    }
}

// --- Initialize reader audio ---
let readerAudio = null;

function initReaderAudio() {
    if (window.speechSynthesis) {
        readerAudio = new ReaderAudio();
        // Load voices (they may be loaded asynchronously)
        if (speechSynthesis.getVoices().length > 0) {
            readerAudio.loadVoices();
        } else {
            speechSynthesis.onvoiceschanged = () => {
                readerAudio.loadVoices();
            };
        }
    } else {
        console.warn('Speech synthesis not supported.');
    }
}

// --- Event bindings ---
document.addEventListener('DOMContentLoaded', function() {
    initReaderAudio();

    // Toggle button in reader (e.g., #ttsBtn)
    const toggleBtn = document.getElementById('ttsBtn') || document.getElementById('awAudioModeBtn');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function() {
            if (readerAudio && readerAudio.isPlaying) {
                readerAudio.stop();
            } else {
                readerAudio.start();
            }
        });
    }

    // Control buttons
    const playBtn = document.getElementById('awAudioPlayBtn');
    if (playBtn) {
        playBtn.addEventListener('click', function() {
            if (readerAudio) readerAudio.togglePlay();
        });
    }

    const stopBtn = document.getElementById('awAudioStopBtn');
    if (stopBtn) {
        stopBtn.addEventListener('click', function() {
            if (readerAudio) readerAudio.stop();
        });
    }

    const nextBtn = document.getElementById('awAudioNextBtn');
    if (nextBtn) {
        nextBtn.addEventListener('click', function() {
            if (readerAudio) {
                readerAudio.synth.cancel();
                readerAudio.currentParagraph++;
                readerAudio.playParagraph();
            }
        });
    }

    const prevBtn = document.getElementById('awAudioPrevBtn');
    if (prevBtn) {
        prevBtn.addEventListener('click', function() {
            if (readerAudio) {
                readerAudio.synth.cancel();
                readerAudio.currentParagraph = Math.max(0, readerAudio.currentParagraph - 1);
                readerAudio.playParagraph();
            }
        });
    }

    const speedSlider = document.getElementById('awAudioSpeed');
    if (speedSlider) {
        speedSlider.addEventListener('input', function() {
            if (readerAudio) readerAudio.setRate(parseFloat(this.value));
        });
        // Restore saved speed
        const savedRate = localStorage.getItem('readerAudioRate');
        if (savedRate) {
            speedSlider.value = savedRate;
            document.getElementById('awAudioSpeedLabel').textContent = parseFloat(savedRate).toFixed(1) + 'x';
        }
    }

    const voiceSelect = document.getElementById('awAudioVoice');
    if (voiceSelect) {
        voiceSelect.addEventListener('change', function() {
            if (readerAudio) readerAudio.setVoiceByName(this.value);
        });
    }

    // Keyboard shortcuts (only when audio controls are visible)
    document.addEventListener('keydown', function(e) {
        const controls = document.getElementById('awAudioControls');
        if (!controls || controls.style.display === 'none') return;
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') return;

        switch (e.key) {
            case ' ':
                e.preventDefault();
                if (readerAudio) readerAudio.togglePlay();
                break;
            case 'ArrowRight':
                e.preventDefault();
                if (readerAudio) {
                    readerAudio.synth.cancel();
                    readerAudio.currentParagraph++;
                    readerAudio.playParagraph();
                }
                break;
            case 'ArrowLeft':
                e.preventDefault();
                if (readerAudio) {
                    readerAudio.synth.cancel();
                    readerAudio.currentParagraph = Math.max(0, readerAudio.currentParagraph - 1);
                    readerAudio.playParagraph();
                }
                break;
        }
    });

    // Hook into page navigation (if goToPage is available)
    if (typeof goToPage === 'function') {
        const originalGoTo = goToPage;
        goToPage = function(pageNum) {
            originalGoTo(pageNum);
            // If audio is playing, reload paragraphs for new page
            if (readerAudio && readerAudio.isPlaying) {
                readerAudio.currentPage = pageNum;
                setTimeout(() => {
                    readerAudio.loadParagraphs();
                    readerAudio.currentParagraph = 0;
                    readerAudio.playParagraph();
                }, 300);
            }
        };
    }
});
</script>
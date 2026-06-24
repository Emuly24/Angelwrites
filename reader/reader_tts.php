<?php
// ============================================================
//  READER_TTS.PHP – Enhanced Text‑to‑Speech with Word Highlighting
//  Include this in reader.php (before closing </body>)
// ============================================================
?>
<style>
#awTtsControls {
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
#awTtsControls .controls-row {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
#awTtsControls button {
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
#awTtsControls button:hover {
    transform: scale(1.05);
    background: var(--rose-dark);
}
#awTtsControls button.secondary {
    background: var(--border);
    color: var(--text);
}
#awTtsControls button.secondary:hover {
    background: var(--text-light);
    color: white;
}
#awTtsControls select, #awTtsControls input[type="range"] {
    padding: 4px 8px;
    border-radius: 6px;
    border: 1px solid var(--border);
    background: var(--input-bg);
    color: var(--text);
    font-size: 0.8rem;
}
#awTtsControls input[type="range"] {
    width: 80px;
    accent-color: var(--rose);
}
#awTtsControls .info {
    font-size: 0.8rem;
    color: var(--text-light);
    white-space: nowrap;
}
.tts-highlight {
    background: rgba(219, 161, 162, 0.3) !important;
    padding: 0 2px !important;
    border-radius: 2px !important;
    transition: background 0.1s;
}
</style>

<div id="awTtsControls">
    <div class="controls-row">
        <button id="awTtsPrevBtn" class="secondary" title="Previous paragraph"><i class="fas fa-step-backward"></i></button>
        <button id="awTtsPlayBtn" title="Play/Pause"><i class="fas fa-play"></i></button>
        <button id="awTtsStopBtn" class="secondary" title="Stop"><i class="fas fa-stop"></i></button>
        <button id="awTtsNextBtn" class="secondary" title="Next paragraph"><i class="fas fa-step-forward"></i></button>
    </div>
    <div class="controls-row">
        <span class="info" id="awTtsSpeedLabel">1.0x</span>
        <input type="range" id="awTtsSpeed" min="0.5" max="2.0" step="0.1" value="1.0">
        <select id="awTtsVoice"></select>
    </div>
</div>

<script>
class ReaderTTS {
    constructor() {
        this.synth = window.speechSynthesis;
        this.currentUtterance = null;
        this.isPlaying = false;
        this.isPaused = false;
        this.rate = parseFloat(localStorage.getItem('ttsRate') || '1.0');
        this.pitch = parseFloat(localStorage.getItem('ttsPitch') || '1.0');
        this.voiceName = localStorage.getItem('ttsVoice') || '';
        this.voice = null;
        this.paragraphs = [];
        this.currentParagraph = 0;
        this.currentPage = 1;
        this.totalPages = 1;
        this.wordRanges = []; // for highlighting
        this.highlightClass = 'tts-highlight';
        this.isLoadingNextPage = false;
        this.textNodes = [];
    }

    // --- Load voices ---
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

    async init() {
        const voices = await this.loadVoices();
        this.populateVoiceSelect(voices);
        if (this.voiceName) {
            this.voice = voices.find(v => v.name === this.voiceName) || null;
        }
    }

    populateVoiceSelect(voices) {
        const select = document.getElementById('awTtsVoice');
        select.innerHTML = '';
        voices.forEach(v => {
            const opt = document.createElement('option');
            opt.value = v.name;
            opt.textContent = v.name + (v.default ? ' (default)' : '');
            if (v.name === this.voiceName) opt.selected = true;
            select.appendChild(opt);
        });
    }

    // --- Load paragraphs from current page ---
    loadParagraphs() {
        const container = document.querySelector('.page-content-inner[data-page="' + this.currentPage + '"]') ||
                          document.querySelector('#scroll-container .page-content-inner') ||
                          document.querySelector('#flip-container .flip-page-custom') ||
                          document.body;

        this.textNodes = [];
        this.paragraphs = [];
        // Walk all text nodes inside container
        const walker = document.createTreeWalker(container, NodeFilter.SHOW_TEXT, null, false);
        let node;
        let idx = 0;
        while (node = walker.nextNode()) {
            const text = node.textContent.trim();
            if (text.length > 15) { // minimum length for a sentence
                // Store the text node and its parent for highlighting
                this.textNodes.push(node);
                this.paragraphs.push({
                    index: idx++,
                    text: text,
                    node: node,
                    parent: node.parentElement
                });
            }
        }
        return this.paragraphs.length;
    }

    // --- Speak current paragraph with word highlighting ---
    speakParagraph(index = null) {
        if (index !== null) {
            this.currentParagraph = index;
        }

        if (this.currentParagraph >= this.paragraphs.length) {
            // Try to load next page
            if (this.currentPage < this.totalPages) {
                this.loadNextPage();
                return;
            } else {
                this.stop();
                alert('🎉 You have finished the entire book in TTS mode!');
                return;
            }
        }

        const paragraph = this.paragraphs[this.currentParagraph];
        if (!paragraph) {
            this.currentParagraph++;
            this.speakParagraph();
            return;
        }

        const text = paragraph.text;
        if (text.length < 2) {
            this.currentParagraph++;
            this.speakParagraph();
            return;
        }

        // Reset word ranges
        this.wordRanges = [];
        this.clearHighlight();

        const utterance = new SpeechSynthesisUtterance(text);
        utterance.lang = 'en-US';
        utterance.rate = this.rate;
        utterance.pitch = this.pitch;
        if (this.voice) utterance.voice = this.voice;

        // Real‑time word highlighting
        utterance.onboundary = (event) => {
            if (event.name === 'word') {
                const charIndex = event.charIndex;
                const wordLength = event.charLength || 0;
                this.highlightWord(charIndex, charIndex + wordLength, paragraph.node);
            }
        };

        utterance.onstart = () => {
            this.isPlaying = true;
            this.isPaused = false;
            this.updatePlayButton();
        };

        utterance.onend = () => {
            this.isPlaying = false;
            this.clearHighlight();
            this.currentParagraph++;
            this.speakParagraph();
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

    // --- Highlight a range of text within the paragraph node ---
    highlightWord(start, end, textNode) {
        this.clearHighlight();
        if (!textNode || start < 0 || end < 0) return;

        const range = document.createRange();
        try {
            range.setStart(textNode, start);
            range.setEnd(textNode, end);
        } catch (e) {
            // Character indices may be off; fallback to highlight entire paragraph
            const parent = textNode.parentElement;
            if (parent) {
                parent.classList.add(this.highlightClass);
            }
            return;
        }

        // Create a wrapper span around the range
        const span = document.createElement('span');
        span.className = this.highlightClass;
        const fragment = range.extractContents();
        span.appendChild(fragment);
        range.insertNode(span);
        this.wordRanges.push(span);
    }

    clearHighlight() {
        // Remove all highlight spans
        document.querySelectorAll('.' + this.highlightClass).forEach(el => {
            const parent = el.parentNode;
            while (el.firstChild) {
                parent.insertBefore(el.firstChild, el);
            }
            parent.removeChild(el);
        });
        this.wordRanges = [];
    }

    // --- Controls ---
    togglePlay() {
        if (this.isPaused) {
            this.resume();
        } else if (this.isPlaying) {
            this.pause();
        } else {
            this.start();
        }
    }

    start() {
        if (!this.synth) {
            alert('Your browser does not support text‑to‑speech.');
            return;
        }
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
        this.speakParagraph();
        this.showControls();
    }

    pause() {
        if (this.isPlaying && !this.isPaused) {
            this.synth.pause();
            this.isPaused = true;
            this.updatePlayButton();
        }
    }

    resume() {
        if (this.isPaused) {
            this.synth.resume();
            this.isPaused = false;
            this.updatePlayButton();
        }
    }

    stop() {
        this.synth.cancel();
        this.isPlaying = false;
        this.isPaused = false;
        this.currentUtterance = null;
        this.clearHighlight();
        this.hideControls();
        this.updatePlayButton();
    }

    // --- UI ---
    showControls() {
        const controls = document.getElementById('awTtsControls');
        if (controls) controls.style.display = 'flex';
        document.getElementById('page-viewport').style.opacity = '0.8';
        this.updatePlayButton();
    }

    hideControls() {
        const controls = document.getElementById('awTtsControls');
        if (controls) controls.style.display = 'none';
        document.getElementById('page-viewport').style.opacity = '1';
        this.clearHighlight();
    }

    updatePlayButton() {
        const btn = document.getElementById('awTtsPlayBtn');
        if (this.isPaused) {
            btn.innerHTML = '<i class="fas fa-play"></i>';
        } else if (this.isPlaying) {
            btn.innerHTML = '<i class="fas fa-pause"></i>';
        } else {
            btn.innerHTML = '<i class="fas fa-play"></i>';
        }
    }

    // --- Navigation ---
    loadNextPage() {
        if (this.isLoadingNextPage) return;
        this.isLoadingNextPage = true;
        this.synth.cancel();
        const nextPage = this.currentPage + 1;
        if (typeof goToPage === 'function') {
            goToPage(nextPage);
            setTimeout(() => {
                this.currentPage = nextPage;
                this.loadParagraphs();
                this.currentParagraph = 0;
                this.speakParagraph();
                this.isLoadingNextPage = false;
            }, 500);
        } else {
            this.stop();
            alert('Please turn to page ' + nextPage + ' manually.');
            this.isLoadingNextPage = false;
        }
    }

    prevParagraph() {
        this.synth.cancel();
        this.currentParagraph = Math.max(0, this.currentParagraph - 1);
        this.speakParagraph();
    }

    nextParagraph() {
        this.synth.cancel();
        this.currentParagraph++;
        this.speakParagraph();
    }

    // --- Settings ---
    setRate(rate) {
        this.rate = rate;
        localStorage.setItem('ttsRate', rate);
        document.getElementById('awTtsSpeedLabel').textContent = rate.toFixed(1) + 'x';
        if (this.isPlaying) {
            this.synth.cancel();
            this.speakParagraph(this.currentParagraph);
        }
    }

    setPitch(pitch) {
        this.pitch = pitch;
        localStorage.setItem('ttsPitch', pitch);
    }

    setVoiceByName(name) {
        this.voiceName = name;
        localStorage.setItem('ttsVoice', name);
        this.loadVoices().then(voices => {
            this.voice = voices.find(v => v.name === name) || null;
            if (this.isPlaying) {
                this.synth.cancel();
                this.speakParagraph(this.currentParagraph);
            }
        });
    }
}

// --- Initialize ---
let tts = null;

document.addEventListener('DOMContentLoaded', async function() {
    if (window.speechSynthesis) {
        tts = new ReaderTTS();
        await tts.init();
        // Restore speed display
        const savedRate = localStorage.getItem('ttsRate');
        if (savedRate) {
            document.getElementById('awTtsSpeed').value = savedRate;
            document.getElementById('awTtsSpeedLabel').textContent = parseFloat(savedRate).toFixed(1) + 'x';
        }
    } else {
        console.warn('Speech synthesis not supported.');
    }

    // --- Bind UI controls ---
    const playBtn = document.getElementById('awTtsPlayBtn');
    if (playBtn) {
        playBtn.addEventListener('click', () => { if (tts) tts.togglePlay(); });
    }

    const stopBtn = document.getElementById('awTtsStopBtn');
    if (stopBtn) {
        stopBtn.addEventListener('click', () => { if (tts) tts.stop(); });
    }

    const prevBtn = document.getElementById('awTtsPrevBtn');
    if (prevBtn) {
        prevBtn.addEventListener('click', () => { if (tts) tts.prevParagraph(); });
    }

    const nextBtn = document.getElementById('awTtsNextBtn');
    if (nextBtn) {
        nextBtn.addEventListener('click', () => { if (tts) tts.nextParagraph(); });
    }

    const speedSlider = document.getElementById('awTtsSpeed');
    if (speedSlider) {
        speedSlider.addEventListener('input', function() {
            if (tts) tts.setRate(parseFloat(this.value));
        });
    }

    const voiceSelect = document.getElementById('awTtsVoice');
    if (voiceSelect) {
        voiceSelect.addEventListener('change', function() {
            if (tts) tts.setVoiceByName(this.value);
        });
    }

    // --- Toggle button in reader (e.g., #ttsBtn) ---
    const toggleBtn = document.getElementById('ttsBtn') || document.getElementById('awTtsModeBtn');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function() {
            if (tts && tts.isPlaying) {
                tts.stop();
            } else if (tts) {
                tts.start();
            }
        });
    }

    // --- Keyboard shortcuts ---
    document.addEventListener('keydown', function(e) {
        const controls = document.getElementById('awTtsControls');
        if (!controls || controls.style.display === 'none') return;
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') return;

        switch (e.key) {
            case ' ':
                e.preventDefault();
                if (tts) tts.togglePlay();
                break;
            case 'ArrowRight':
                e.preventDefault();
                if (tts) tts.nextParagraph();
                break;
            case 'ArrowLeft':
                e.preventDefault();
                if (tts) tts.prevParagraph();
                break;
        }
    });

    // --- Hook into page navigation ---
    if (typeof goToPage === 'function') {
        const originalGoTo = goToPage;
        goToPage = function(pageNum) {
            originalGoTo(pageNum);
            if (tts && tts.isPlaying) {
                tts.currentPage = pageNum;
                setTimeout(() => {
                    tts.loadParagraphs();
                    tts.currentParagraph = 0;
                    tts.speakParagraph();
                }, 300);
            }
        };
    }
});
</script>
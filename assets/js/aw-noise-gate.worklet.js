// ============================================================
//  AW-NOISE-GATE.WORKLET.JS – adaptive noise reduction (M1)
// ============================================================
// Runs inside an AudioWorklet (loaded by assets/js/aw-media.js). It measures the background noise
// level continuously (the quietest moments of the last ~3 seconds) and turns the sound down while
// nobody is speaking, so hiss, hum and room noise between words and phrases disappear. Speech
// opens it at once and it stays open briefly after each word, so soft endings are not cut off.
//   reduction: how loud the noise stays (1 = untouched, 0.07 = about -23 dB)
//   enabled:   0 bypasses the gate
class AWNoiseGate extends AudioWorkletProcessor {
    static get parameterDescriptors() {
        return [
            { name: 'reduction', defaultValue: 0.35, minValue: 0, maxValue: 1, automationRate: 'k-rate' },
            { name: 'enabled', defaultValue: 1, minValue: 0, maxValue: 1, automationRate: 'k-rate' },
        ];
    }

    constructor() {
        super();
        this.env = 0;                 // smoothed level of the signal
        this.started = false;
        this.floor = 0.003;           // estimated background noise level
        this.chunkMin = Infinity;     // quietest level in the current quarter second
        this.chunkBlocks = 0;
        this.mins = [];               // quietest level of each of the last 12 quarter seconds
        this.open = false;
        this.hold = 0;
        this.gain = 1;
        this.attack = 1 - Math.exp(-1 / (0.003 * sampleRate));
        this.release = 1 - Math.exp(-1 / (0.09 * sampleRate));
    }

    process(inputs, outputs, parameters) {
        const input = inputs[0];
        const output = outputs[0];
        if (!input || input.length === 0 || !input[0]) {
            return true;
        }
        const n = input[0].length;

        let sum = 0;
        for (let c = 0; c < input.length; c++) {
            const ch = input[c];
            for (let i = 0; i < n; i++) sum += ch[i] * ch[i];
        }
        const rms = Math.sqrt(sum / (n * input.length)) + 1e-9;
        this.env = this.started ? this.env * 0.7 + rms * 0.3 : rms; // start from the first real level
        this.started = true;

        // Noise floor = the quietest moment of the last ~3 s ("minimum statistics").
        this.chunkMin = Math.min(this.chunkMin, this.env);
        if (++this.chunkBlocks >= Math.round(0.25 * sampleRate / n)) {
            this.mins.push(this.chunkMin);
            if (this.mins.length > 12) this.mins.shift();
            this.chunkMin = Infinity;
            this.chunkBlocks = 0;
            const m = Math.min.apply(null, this.mins);
            this.floor = Math.min(0.05, Math.max(0.00002, m));
        }

        const enabled = parameters.enabled[0] >= 0.5;
        const reduction = parameters.reduction[0];
        const openAt = this.floor * 3.2;       // about 10 dB above the noise
        if (this.env > openAt) {
            this.open = true;
            this.hold = Math.round(0.15 * sampleRate / n);
        } else if (this.env < openAt * 0.7) {
            if (this.hold > 0) this.hold--; else this.open = false;
        }
        const target = !enabled || this.open ? 1 : reduction;

        let g = this.gain;
        for (let c = 0; c < output.length; c++) {
            const inCh = input[Math.min(c, input.length - 1)];
            const outCh = output[c];
            g = this.gain;
            for (let i = 0; i < n; i++) {
                g += (target - g) * (target > g ? this.attack : this.release);
                outCh[i] = inCh[i] * g;
            }
        }
        this.gain = g;
        return true;
    }
}

registerProcessor('aw-noise-gate', AWNoiseGate);

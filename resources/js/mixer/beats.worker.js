// Web Worker of the beat grid (docs/frontend/dj-mode.md, D1): receives the drums and bass stems in chunks, folds them
// into level curves as they arrive and runs the analysis once the last chunk is in, so the page never stalls on it.
import { analyseFeatures, FeatureExtractor } from './beats.js';

let extractor = null;
let seedBpm = null;

self.onmessage = ({ data }) => {
    if (data.type === 'start') {
        extractor = new FeatureExtractor(data.sampleRate);
        seedBpm = data.seedBpm;
    } else if (data.type === 'chunk') {
        extractor.push(data.drums, data.bass);
    } else if (data.type === 'done') {
        try {
            self.postMessage({ type: 'result', ...analyseFeatures(extractor.finish(), seedBpm) });
        } catch (e) {
            self.postMessage({ type: 'error', message: String(e?.message ?? e) });
        }
        extractor = null;
    }
};

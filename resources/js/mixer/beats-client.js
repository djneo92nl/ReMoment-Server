// Runs the beat analysis of a deck's stems in a Web Worker (beats.worker.js) and resolves with `{ grid, reason }`.
// The stems are sent in chunks of 1M samples (copies, so the decoded buffers stay untouched and the worker never
// holds a whole stem). Without Worker support it runs on the page's own thread, chunk by chunk with a pause in between.
import { analyseFeatures, FeatureExtractor } from './beats.js';

const CHUNK = 1 << 20;

const channels = (buffer) => (buffer ? Array.from({ length: buffer.numberOfChannels }, (_, c) => buffer.getChannelData(c)) : null);

/**
 * @param {{ drums: AudioBuffer, bass?: AudioBuffer }} buffers
 * @param {number|null} seedBpm the analysed BPM
 */
export async function findGrid(buffers, seedBpm) {
    const drums = channels(buffers.drums);
    const bass = channels(buffers.bass);
    const length = drums[0].length;
    const sampleRate = buffers.drums.sampleRate;
    const span = (list, at) => list?.map((c) => c.subarray(at, Math.min(at + CHUNK, c.length))) ?? null;

    if (typeof Worker === 'undefined') {
        const extractor = new FeatureExtractor(sampleRate);
        for (let at = 0; at < length; at += CHUNK) {
            extractor.push(span(drums, at), span(bass, at));
            await new Promise((resolve) => setTimeout(resolve, 0));
        }

        return analyseFeatures(extractor.finish(), seedBpm);
    }

    const worker = new Worker(new URL('./beats.worker.js', import.meta.url), { type: 'module' });
    try {
        return await new Promise((resolve, reject) => {
            worker.onmessage = ({ data }) => (data.type === 'result' ? resolve({ grid: data.grid, reason: data.reason }) : reject(new Error(data.message)));
            worker.onerror = (e) => reject(new Error(e.message || 'The beat worker failed.'));
            worker.postMessage({ type: 'start', sampleRate, seedBpm });
            for (let at = 0; at < length; at += CHUNK) {
                const d = span(drums, at).map((c) => c.slice());
                const b = span(bass, at)?.map((c) => c.slice()) ?? null;
                worker.postMessage({ type: 'chunk', drums: d, bass: b }, [...d.map((c) => c.buffer), ...(b ?? []).map((c) => c.buffer)]);
            }
            worker.postMessage({ type: 'done' });
        });
    } finally {
        worker.terminate();
    }
}

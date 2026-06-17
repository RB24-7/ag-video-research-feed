#!/usr/bin/env node

import { readFile } from 'node:fs/promises';

const manifestPath = process.argv[2] || 'data/video-manifest.json';
const manifest = JSON.parse(await readFile(manifestPath, 'utf8'));

const rows = [];
const failures = [];
const seenVideoIds = new Set();
const seenKeywordIds = new Map();

for (const [setIndex, set] of arrayValue(manifest.sets).entries()) {
  if (!set || typeof set !== 'object') continue;

  const setId = slugId(set.studySetId || set.id || `set-${setIndex + 1}`);
  const setKeywordDetails = readKeywordDetails(set);
  const seed = set.seed || set.original || set.ad || set.reference || set;
  const seedId = slugId(`${setId}-seed`);

  addVideo({
    videoId: seedId,
    studySetId: setId,
    role: 'seed',
    title: seed.title || set.title || 'Original ad',
    source: seed,
    fallbackKeywordDetails: setKeywordDetails
  });

  const variants = arrayValue(set.variants || set.generated || set.outputs);
  variants.forEach((variant, index) => {
    if (!variant || typeof variant !== 'object') return;
    const videoId = slugId(`${setId}-variant-${index + 1}`);
    const label = variant.shortLabel || variant.label || variant.modelName || `V${index + 1}`;
    addVideo({
      videoId,
      studySetId: setId,
      role: 'variant',
      title: `${label} variant`,
      modelName: variant.modelName || variant.model || label,
      source: variant,
      fallbackKeywordDetails: setKeywordDetails
    });
  });
}

for (const [index, item] of arrayValue(manifest.videos).entries()) {
  if (!item || typeof item !== 'object') continue;
  const videoId = slugId(item.id || item.videoId || item.url || item.videoUrl || `video-${index + 1}`);
  addVideo({
    videoId,
    studySetId: item.studySetId || '',
    role: item.studyRole || 'video',
    title: item.title || videoId,
    modelName: item.modelName || '',
    source: item,
    fallbackKeywordDetails: []
  });
}

if (!rows.length) {
  failures.push(`No video keyword rows found in ${manifestPath}.`);
}

if (failures.length) {
  console.error('Keyword map verification failed:');
  for (const failure of failures) console.error(`- ${failure}`);
  process.exit(1);
}

console.log(`Verified ${rows.length} keyword mappings across ${seenVideoIds.size} videos.`);
console.log('Each effective keyword_id is scoped as "<video_id>:<keyword>".');
console.table(rows.map((row) => ({
  video_id: row.videoId,
  role: row.role,
  model: row.modelName,
  keyword_id: row.keywordId,
  keyword_text: row.text,
  raw_keyword_id: row.rawKeywordId || ''
})));

function addVideo({ videoId, studySetId, role, title, modelName = '', source, fallbackKeywordDetails }) {
  if (!videoId) return;
  if (seenVideoIds.has(videoId)) {
    failures.push(`Duplicate video id "${videoId}".`);
    return;
  }
  seenVideoIds.add(videoId);

  const ownKeywordDetails = readKeywordDetails(source);
  const keywordDetails = ownKeywordDetails.length ? ownKeywordDetails : fallbackKeywordDetails;
  if (!keywordDetails.length) {
    failures.push(`Video "${videoId}" has no keyword choices.`);
    return;
  }

  const seenTexts = new Set();
  keywordDetails.forEach((keyword, index) => {
    const text = cleanText(keyword?.text || keyword);
    if (!text) return;

    const textKey = slugId(text);
    if (seenTexts.has(textKey)) {
      failures.push(`Video "${videoId}" repeats keyword text "${text}".`);
      return;
    }
    seenTexts.add(textKey);

    const keywordId = `${videoId}:${textKey}`;
    const existingVideo = seenKeywordIds.get(keywordId);
    if (existingVideo && existingVideo !== videoId) {
      failures.push(`Keyword id "${keywordId}" is shared by "${existingVideo}" and "${videoId}".`);
      return;
    }
    seenKeywordIds.set(keywordId, videoId);

    rows.push({
      videoId,
      studySetId,
      role,
      title,
      modelName,
      keywordId,
      rawKeywordId: keyword?.id || '',
      text,
      rank: keyword?.rank ?? index + 1
    });
  });
}

function readKeywordDetails(source) {
  if (!source || typeof source !== 'object') return [];

  const detailRows = arrayValue(source.questionKeywordDetails || source.keywordDetails);
  if (detailRows.length) {
    return detailRows
      .map((keyword, index) => ({
        id: keyword?.id || '',
        text: cleanText(typeof keyword === 'string' ? keyword : keyword?.text),
        confidence: keyword?.confidence ?? null,
        source: keyword?.source || 'pipeline',
        rank: keyword?.rank ?? index + 1
      }))
      .filter((keyword) => keyword.text);
  }

  return arrayValue(source.questionKeywords || source.keywords)
    .map((keyword, index) => ({
      id: '',
      text: cleanText(keyword),
      confidence: null,
      source: 'manifest',
      rank: index + 1
    }))
    .filter((keyword) => keyword.text);
}

function arrayValue(value) {
  return Array.isArray(value) ? value : [];
}

function cleanText(value) {
  return String(value || '').trim();
}

function slugId(value) {
  const slug = cleanText(value)
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');

  return slug || 'video';
}

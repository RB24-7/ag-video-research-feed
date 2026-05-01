# Ag Video Research Feed

A TikTok/Reels-style research page for agricultural ad testing. Participants open the page, watch randomized vertical ads, interact naturally, and answer quick keyword/variant prompts only after an original ad gets enough engagement.

Large videos stay in Dropbox. The repo does not hardcode individual video links. Instead, a server-side manifest builder reads Dropbox and writes the JSON that the HTML page loads.

## Main Files

- `research-feed.html` is the standalone participant page.
- `scripts/build-dropbox-manifest.mjs` reads Dropbox and generates `data/video-manifest.json`.
- `data/video-manifest.json` is generated output and is intentionally ignored by git.
- `research-feed.html?admin=1` opens the hidden researcher panel for local exports and testing.

## Dropbox Structure

Keep Dropbox as the source of truth:

```text
Ag Video Research/
  original-ads/
    apple-season-ad.mp4
    pistachios-green-nut-ad.mp4

  generated-outputs/
    apple-season-ad/
      final_keywords.csv
      mining_summary.csv
      new_ad_anythingv5.mp4
      new_ad_revanimated.mp4
      new_ad_toonyou.mp4
      new_ad_dreamshaper.mp4
      new_ad_sd15.mp4
      extraction_done.flag
```

Folder names under `generated-outputs/` should match the original ad filename slug. For example, `Apple Season Ad.mp4` becomes `apple-season-ad/`.

## Build The Manifest

Create a `.env` file on the server:

```bash
DROPBOX_ACCESS_TOKEN=
DROPBOX_ORIGINAL_ADS_FOLDER=https://www.dropbox.com/scl/fo/.../original-ads?rlkey=...
DROPBOX_GENERATED_OUTPUTS_FOLDER=https://www.dropbox.com/scl/fo/.../generated-outputs?rlkey=...
MANIFEST_OUTPUT=data/video-manifest.json
```

Then run:

```bash
node scripts/build-dropbox-manifest.mjs
```

The builder will:

- List every MP4 in `original-ads/`.
- Create or reuse Dropbox shared links for the original videos.
- Recursively scan `generated-outputs/` for folders that contain `new_ad_*.mp4` files.
- Match each original ad to its generated-output folder.
- Add all `new_ad_*.mp4` generated variants.
- Try to pull keywords from `final_keywords.csv`, `mining_summary.csv`, or `merged_keywords.csv`.
- Write `data/video-manifest.json`.
- Warn about original ads without generated videos and generated folders that did not match an original ad.

For troubleshooting folder-name mismatches, run:

```bash
node scripts/build-dropbox-manifest.mjs --debug
```

The Dropbox token stays on the server. Never paste it into `research-feed.html` or commit it to git.

## Deploy

Upload these files while keeping the same structure:

```text
research-feed.html
data/video-manifest.json
```

If Dropbox videos change later, update Dropbox and rerun the manifest builder. The HTML does not need to change.

## Research Flow

1. The page shuffles the original ads so each participant gets a random order.
2. A participant watches, skips, pauses, mutes, or likes videos naturally.
3. If they like an original ad or watch enough of it, the page unlocks that ad's generated variants.
4. The participant picks the generated version they prefer and chooses the keyword they think best matches the ad.
5. The hidden admin view can export the captured events for analysis.

## Tracked Events

The standalone page records interaction events in the browser, including impressions, watch time, progress milestones, completions, skips, pauses, likes, mute changes, visibility changes, generated-video unlocks, variant picks, and keyword choices.

Cloud auto-save is intentionally disabled in the checked-in file. If a server endpoint or Supabase setup is added later, keep keys on the server and do not commit secrets to this repo.

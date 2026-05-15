# Ag Video Research Feed

A TikTok/Reels-style research page for agricultural ad testing. Participants open the page, watch randomized vertical ads, interact naturally, and answer quick keyword/variant prompts only after an original ad gets enough engagement.

Large videos stay in Dropbox. The repo does not hardcode individual video links. Instead, a server-side manifest builder reads Dropbox and writes the JSON that the HTML page loads.

## Main Files

- `research-feed.html` is the standalone participant page.
- `video-proxy.php` streams Dropbox videos with a browser-friendly `video/mp4` header when the page is hosted on Apache/PHP.
- `study-save.php` validates study responses against the manifest and saves analytics/responses to Supabase.
- `supabase-schema.sql` creates the Supabase tables and researcher views.
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
- Recursively scan `generated-outputs/` for folders that contain generated MP4 files.
- Match each original ad to its generated-output folder, including cleaner folder names that share the same key words or drop leading date prefixes.
- Add only generated variants named `new_ad_*_web.mp4` or `all_new_ads_*.web.mp4`.
- Ignore raw generated MP4 files without a `.web` or `_web` marker so browsers do not accidentally load problematic OpenCV outputs.
- Try to pull keywords from `final_keywords.csv`, `mining_summary.csv`, or `merged_keywords.csv` while ignoring CSV header columns such as `Frame` and `Confidence`.
- Write `data/video-manifest.json`.
- Report discovered versus matched generated-video counts, then warn about unmatched originals or folders.

For troubleshooting folder-name mismatches, run:

```bash
node scripts/build-dropbox-manifest.mjs --debug
```

The Dropbox token stays on the server. Never paste it into `research-feed.html` or commit it to git.

## Supabase Save

Run `supabase-schema.sql` in the Supabase SQL editor once. Then add these values to the server `.env`:

```bash
SUPABASE_URL=https://your-project.supabase.co
SUPABASE_SERVICE_ROLE_KEY=
SUPABASE_EVENTS_TABLE=video_events
SUPABASE_RESPONSES_TABLE=study_responses
```

`SUPABASE_SERVICE_ROLE_KEY` must stay server-side in `.env`. Do not paste it into `research-feed.html`.

When hosted over HTTP, the participant page sends event batches and final responses to `study-save.php`. The endpoint checks every submitted keyword against `data/video-manifest.json`, then stores the manifest-side keyword confidence/source/rank in Supabase. This means participants only see simple keyword chips, while researchers can query confidence scores later.

Useful Supabase queries:

```sql
select * from public.video_analytics_summary order by likes desc, impressions desc;
select * from public.video_events where video_id = 'apple-season-ad-variant-1' order by created_at;
select * from public.video_keyword_choices where video_id = 'apple-season-ad-variant-1';
select * from public.video_reason_choices where video_id = 'apple-season-ad-variant-1';
```

## Generated Video Names

For browser playback, Colab should export generated MP4 files as H.264/AVC (`avc1`), `yuv420p`, and fast-start MP4s. OpenCV `mp4v` files can show as a green screen on some Macs/browsers.

The manifest builder only recognizes generated variants with either web-safe naming style:

```text
new_ad_anythingv5_web.mp4
new_ad_revanimated_web.mp4
new_ad_toonyou_web.mp4
new_ad_dreamshaper_web.mp4
new_ad_sd15_web.mp4

all_new_ads_anythingv5.web.mp4
all_new_ads_revanimated.web.mp4
all_new_ads_toonyou.web.mp4
all_new_ads_dreamshaper.web.mp4
all_new_ads_sd15.web.mp4
```

Put those files inside the generated-output folder for the matching original ad, then rerun the manifest builder. If the `all_new_ads_*` files are master reels containing every ad stitched together, keep those separate from the per-ad research variants.

## Deploy

Upload these files while keeping the same structure:

```text
research-feed.html
video-proxy.php
study-save.php
supabase-schema.sql
data/video-manifest.json
```

If Dropbox videos change later, update Dropbox and rerun the manifest builder. The HTML does not need to change.

Dropbox may serve MP4 bytes with the wrong MIME type. Hosted pages automatically route Dropbox video URLs through `video-proxy.php`; add `?directDropbox=1` to the page URL only when testing direct Dropbox links.

## Research Flow

1. The page shuffles the original ads so each participant gets a random order.
2. A participant watches, skips, pauses, mutes, or likes videos naturally.
3. If they like one original ad, the page unlocks that ad's generated variants.
4. The participant sees a deterministic random 2-of-5 generated-variant subset for that ad.
5. The participant reacts to the generated variants, chooses the keywords that best match what stood out, and gives quick multiple-choice like/dislike reasons.
6. After the generated responses are saved, the feed returns to original ads and continues looping.

## Tracked Events

The standalone page records interaction events in the browser, including impressions, watch time, progress milestones, completions, skips, pauses, likes, mute changes, visibility changes, generated-video unlocks, variant picks, and keyword choices.

When `study-save.php` has Supabase credentials, those events are saved to `video_events` and final responses are saved to `study_responses`. The download buttons remain available as a backup export path.

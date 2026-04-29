# Ag Video Research Feed

A TikTok/Reels-style research page for agricultural ad testing. The participant experience is intentionally simple: people open the page, watch randomized vertical videos, like or skip naturally, and answer very quick keyword/variant prompts only after an original ad has enough engagement.

The large videos are hosted outside the repo through Dropbox. The repo only stores the page and the lightweight manifest that tells the page what to load.

## Main Files

- `research-feed.html` is the standalone participant page.
- `data/dropbox-videos.json` is the Dropbox video manifest.
- `research-feed.html?admin=1` opens the hidden researcher panel for local exports and testing.
This GitHub version focuses on the standalone Dropbox demo. Older local app/development files are intentionally left out of the repo so the server deployment stays easy to understand.

## Deploy

For the simple server version, upload these while keeping the same folder structure:

```text
research-feed.html
data/dropbox-videos.json
```

Participants only need the URL to `research-feed.html`. The videos stream from Dropbox, so the server does not need to store the large MP4 files.

## Dropbox Manifest

Each item in `sets` represents one original ad and its generated follow-up videos.

```json
{
  "sets": [
    {
      "id": "apple-season-ad",
      "title": "Apple Season Ad",
      "keywords": ["apple", "orchard", "fresh", "california", "harvest"],
      "seed": {
        "url": "https://www.dropbox.com/scl/fi/.../Apple%20Season%20Ad.mp4?dl=0",
        "title": "Original ad"
      },
      "variants": [
        {
          "url": "https://www.dropbox.com/scl/fi/.../new_ad_dreamshaper.mp4?dl=0",
          "modelName": "DreamShaper",
          "shortLabel": "Dream"
        }
      ],
      "outputFolder": "https://www.dropbox.com/scl/fo/..."
    }
  ],
  "videos": []
}
```

Use `keywords` for the quick participant keyword prompt. Use `variants` for the generated videos from the notebook pipeline. Use `outputFolder` only to keep the research data aligned with the Dropbox folder; participants do not need to interact with it.

## Research Flow

1. The page shuffles the original ads so each participant gets a random order.
2. A participant watches, skips, pauses, mutes, or likes videos naturally.
3. If they like an original ad or watch enough of it, the page unlocks that ad's generated variants.
4. The participant picks the generated version they prefer and chooses the keyword they think best matches the ad.
5. The hidden admin view can export the captured events for analysis.

## Tracked Events

The standalone page records interaction events in the browser, including impressions, watch time, progress milestones, completions, skips, pauses, likes, mute changes, visibility changes, generated-video unlocks, variant picks, and keyword choices.

Cloud auto-save is intentionally disabled in the checked-in file. If a server endpoint or Supabase setup is added later, keep keys on the server and do not commit secrets to this repo.

## Local Development

The standalone page does not require a build step. Open `research-feed.html` from a server next to the `data/` folder and it will load the Dropbox-hosted videos from `data/dropbox-videos.json`.

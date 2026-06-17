create table if not exists public.video_events (
  id text primary key,
  participant_id text not null,
  study_label text,
  session_id text not null,
  video_id text not null,
  event_type text not null,
  event_value text,
  metadata jsonb not null default '{}'::jsonb,
  created_at timestamptz not null default now(),
  received_at timestamptz not null default now()
);

create index if not exists video_events_video_id_idx on public.video_events (video_id);
create index if not exists video_events_session_id_idx on public.video_events (session_id);
create index if not exists video_events_event_type_idx on public.video_events (event_type);
create index if not exists video_events_created_at_idx on public.video_events (created_at);

create table if not exists public.study_responses (
  id text primary key,
  participant_id text not null,
  study_label text,
  session_id text not null,
  video_id text not null,
  video_title text,
  study_set_id text,
  seed_id text,
  model_name text,
  round integer,
  liked boolean,
  like_reasons jsonb not null default '[]'::jsonb,
  dislike_reasons jsonb not null default '[]'::jsonb,
  selected_keywords jsonb not null default '[]'::jsonb,
  keyword_details jsonb not null default '[]'::jsonb,
  max_watch_percent numeric,
  responded_at timestamptz,
  submitted_at timestamptz not null default now(),
  raw_response jsonb not null default '{}'::jsonb,
  received_at timestamptz not null default now()
);

alter table public.study_responses
  add column if not exists like_reasons jsonb not null default '[]'::jsonb;

create index if not exists study_responses_video_id_idx on public.study_responses (video_id);
create index if not exists study_responses_study_set_id_idx on public.study_responses (study_set_id);
create index if not exists study_responses_session_id_idx on public.study_responses (session_id);

create or replace view public.video_analytics_summary
with (security_invoker = true) as
select
  video_id,
  count(*) filter (where event_type = 'impression') as impressions,
  count(*) filter (where event_type = 'play') as plays,
  count(*) filter (where event_type = 'like' and event_value = 'on') as likes,
  count(*) filter (where event_type = 'skip') as skips,
  count(*) filter (where event_type = 'complete') as completes,
  count(*) filter (where event_type = 'media_error') as media_errors,
  avg((metadata->>'dwellMs')::numeric) filter (where event_type = 'video_exit' and metadata ? 'dwellMs') as avg_dwell_ms,
  max((metadata->>'watchedPercent')::numeric) filter (where event_type = 'video_exit' and metadata ? 'watchedPercent') as max_watch_percent,
  min(created_at) as first_seen_at,
  max(created_at) as last_seen_at
from public.video_events
group by video_id;

create or replace view public.video_keyword_choices
with (security_invoker = true) as
select
  response.video_id,
  response.study_set_id,
  response.model_name,
  response.participant_id,
  response.session_id,
  keyword->>'id' as keyword_id,
  keyword->>'text' as keyword_text,
  nullif(keyword->>'confidence', '')::numeric as confidence,
  keyword->>'source' as source,
  nullif(keyword->>'rank', '')::numeric as rank,
  response.submitted_at
from public.study_responses response,
  lateral jsonb_array_elements(response.keyword_details) keyword;

create or replace view public.video_reason_choices
with (security_invoker = true) as
select
  video_id,
  study_set_id,
  model_name,
  participant_id,
  session_id,
  'like' as reason_type,
  reason.value #>> '{}' as reason,
  submitted_at
from public.study_responses,
  lateral jsonb_array_elements(like_reasons) reason
union all
select
  video_id,
  study_set_id,
  model_name,
  participant_id,
  session_id,
  'dislike' as reason_type,
  reason.value #>> '{}' as reason,
  submitted_at
from public.study_responses,
  lateral jsonb_array_elements(dislike_reasons) reason;

create or replace view public.study_response_analysis
with (security_invoker = true) as
select
  id,
  participant_id,
  study_label,
  session_id,
  video_id,
  video_title,
  study_set_id,
  seed_id,
  model_name,
  round,
  liked,
  max_watch_percent,
  responded_at,
  submitted_at,
  received_at,
  raw_response->>'videoGenre' as system_genre,
  raw_response->>'genreClassification' as participant_genre,
  raw_response->>'videoQuality' as video_quality,
  case raw_response->>'videoQuality'
    when 'low' then 1
    when 'fair' then 2
    when 'good' then 3
    when 'excellent' then 4
    else null
  end as video_quality_score,
  raw_response->>'relevanceRating' as relevance_rating,
  case raw_response->>'relevanceRating'
    when 'low' then 1
    when 'somewhat' then 2
    when 'strong' then 3
    else null
  end as relevance_score,
  raw_response->>'aiMatchRating' as ai_match_rating,
  case raw_response->>'aiMatchRating'
    when 'poor' then 1
    when 'partial' then 2
    when 'good' then 3
    when 'great' then 4
    else null
  end as ai_match_score,
  jsonb_array_length(like_reasons) as like_reason_count,
  jsonb_array_length(dislike_reasons) as dislike_reason_count,
  jsonb_array_length(selected_keywords) as selected_keyword_count,
  like_reasons,
  dislike_reasons,
  selected_keywords,
  keyword_details,
  raw_response#>>'{roundReflection,matchRating}' as round_match_rating,
  case raw_response#>>'{roundReflection,matchRating}'
    when 'poor' then 1
    when 'partial' then 2
    when 'good' then 3
    when 'great' then 4
    else null
  end as round_match_score,
  coalesce(raw_response#>'{roundReflection,issues}', '[]'::jsonb) as round_issues,
  raw_response#>>'{roundReflection,note}' as round_note
from public.study_responses;

create or replace view public.study_round_reflections
with (security_invoker = true) as
select distinct on (participant_id, session_id, study_set_id)
  participant_id,
  study_label,
  session_id,
  study_set_id,
  seed_id,
  raw_response#>'{roundReflection}' as round_reflection,
  raw_response#>>'{roundReflection,matchRating}' as match_rating,
  case raw_response#>>'{roundReflection,matchRating}'
    when 'poor' then 1
    when 'partial' then 2
    when 'good' then 3
    when 'great' then 4
    else null
  end as match_score,
  coalesce(raw_response#>'{roundReflection,issues}', '[]'::jsonb) as issues,
  raw_response#>>'{roundReflection,note}' as note,
  submitted_at,
  received_at
from public.study_responses
where raw_response ? 'roundReflection'
  and raw_response#>'{roundReflection}' is not null
order by participant_id, session_id, study_set_id, submitted_at desc;

create or replace view public.study_round_liked_videos
with (security_invoker = true) as
with reflections as (
  select distinct on (participant_id, session_id, study_set_id)
    participant_id,
    study_label,
    session_id,
    study_set_id,
    seed_id,
    raw_response#>'{roundReflection}' as round_reflection,
    submitted_at
  from public.study_responses
  where raw_response ? 'roundReflection'
    and raw_response#>'{roundReflection,likedVideos}' is not null
  order by participant_id, session_id, study_set_id, submitted_at desc
)
select
  reflections.participant_id,
  reflections.study_label,
  reflections.session_id,
  reflections.study_set_id,
  reflections.seed_id,
  liked_video->>'videoId' as video_id,
  liked_video->>'videoTitle' as video_title,
  liked_video->>'modelName' as model_name,
  liked_video->>'genreClassification' as participant_genre,
  liked_video->>'videoQuality' as video_quality,
  case liked_video->>'videoQuality'
    when 'low' then 1
    when 'fair' then 2
    when 'good' then 3
    when 'excellent' then 4
    else null
  end as video_quality_score,
  liked_video->>'relevanceRating' as relevance_rating,
  case liked_video->>'relevanceRating'
    when 'low' then 1
    when 'somewhat' then 2
    when 'strong' then 3
    else null
  end as relevance_score,
  liked_video->>'aiMatchRating' as ai_match_rating,
  case liked_video->>'aiMatchRating'
    when 'poor' then 1
    when 'partial' then 2
    when 'good' then 3
    when 'great' then 4
    else null
  end as ai_match_score,
  coalesce(liked_video->'likeReasons', '[]'::jsonb) as like_reasons,
  coalesce(liked_video->'keywords', '[]'::jsonb) as keywords,
  reflections.submitted_at
from reflections,
  lateral jsonb_array_elements(reflections.round_reflection->'likedVideos') liked_video;

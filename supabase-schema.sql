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

create or replace view public.video_analytics_summary as
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

create or replace view public.video_keyword_choices as
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

create or replace view public.video_reason_choices as
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

---
title: Caption tracks
category: Digital objects and viewers
order: 30
routes: captions.*
heratio: caption-tracks-user-guide.md
---

# Caption tracks

Audio and video can carry caption tracks: timed text the player shows or reads out. They are kept with the instantiation, apart from the media file, so you can add, change and remove them without uploading the recording again.

## Kinds of track

| Kind | Use |
|---|---|
| Subtitles | The speech, in the same language or translated |
| Captions | The speech and the other sounds that matter, for viewers who are deaf or hard of hearing |
| Descriptions | What is seen, described in words, for viewers who are blind or have low vision |
| Chapters | Named points in a long recording to jump to |

These are the kinds HTML5 media players understand.

## Adding one

On the instantiation's page, under **Captions**, add a track:

1. Choose its kind and its language, as a BCP 47 code such as `en` or `af`.
2. Give it a label for the player's menu, such as *English* or *Afrikaans subtitles*.
3. Upload a WebVTT (`.vtt`) file of at most 2 MB.
4. Tick **Shown by default** for the track the player should start with.
5. Choose **Add the caption track**.

A track of the same language and kind replaces the one there, so correcting a track is a matter of uploading it again.

## In the player

The player on the record's page offers every track, and switches between them without reloading. A track whose file the public may see is offered to the public with it.

## Accessibility

Captions for speech and descriptions for what is seen are what WCAG 2.1 asks of recorded media (success criteria 1.2.2 and 1.2.3 at level A, 1.2.5 at level AA). A recording with speech should have at least one caption or subtitle track.

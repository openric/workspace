---
title: 3D models
category: Describing
order: 60
routes: models.*, instantiations.show
heratio: 3d-model-viewer-user-guide.md
---

# 3D models

A 3D model is a file of an instantiation, like a scan or a recording: upload it on the description's page as any other file. It is kept exactly as it came, with its SHA-256 and its PRONOM format. It comes from Heratio's 3D viewer.

## Formats

| Received as | Shown as |
| --- | --- |
| GLB (binary glTF) | Itself |
| glTF, in a ZIP with its `.bin` file and textures | A GLB Blender makes |
| OBJ, in a ZIP with its `.mtl` file and textures, or alone | A GLB Blender makes |
| STL, PLY, USDZ | A GLB Blender makes |
| A Gaussian splat (`.splat`, `.ksplat`, or a PLY of splats) | The splat viewer |

: 3D formats and how they are shown

Blender also renders a thumbnail, so the model shows in lists, search results, IIIF and offline catalogues. A model larger than 25 MB gets no viewing copy: it stays a download for those who may receive masters. Where Blender is not installed a GLB is still shown, and the other formats are kept as downloads.

What the model is made of (its vertices, faces, textures, materials, animations and dimensions) is read from the master when it arrives. It is shown on the model's page and kept with its preservation metadata as PREMIS significant properties.

## Looking at a model

Drag to turn the model, pinch or scroll to zoom. On a keyboard, select the model, then use the arrow keys. A reader who asks their system for reduced motion sees no turning by itself and no animation playing.

Under the model, **Views, measuring and cross-section** holds:

- **Saved views**: views published for everyone, and, signed in, your own. **Save this view** keeps the one you are looking at.
- **Link to this view**: copies a link that opens the model at the angle, distance and animation you see now.
- **Measure**: click two points on the model; the distance is in the model's units, which for a glTF are metres.
- **Cross-section**: a slice through the model along X, Y or Z, moved with the slider.

A model with animations shows a player: play and pause, the animation to play, and where in it.

On an Android phone or tablet, a model can be placed in the room in augmented reality where its settings allow it. iPhone and iPad need a USDZ copy, which the Workspace does not make yet.

## How a model is shown

Who may change a description opens **How the model is shown, and its hotspots** under the model. Each setting left empty is as the institution's defaults say: turning by itself and how fast, the starting view and the closest and farthest, the field of view, brightness, shadow, background colour, neutral lighting, and augmented reality (on or off, on the floor or a wall, at its real size or not).

The institution's defaults are under **Admin**, **3D models**, for who may manage the institution. Left empty there, each is as Heratio shows a model.

## Hotspots

A hotspot is a labelled point on the model: an annotation, a fact, a link, damage, or a detail worth a close look. On the model's page, click **Click on the model to choose the point**, click the point, give the title and a description in a language, and **Place the hotspot**. Open a hotspot to write it in another language, change its kind, colour or link, or delete it.

A hotspot's text is a draft until the record is published, and is withdrawn with it, as every value of a description is. A reader sees it in their language where it is written in it, else in the language it was first written in. In IIIF a hotspot is a Web Annotation at its point on the model; RiC-O has no term for it, so it is not in the RiC-O export.

## Turntable video

**Make a turntable video** on the model's page renders a video all round the model with Blender and keeps it as the model's access copy, for a page or a reader that cannot show a 3D model. It is made in the background.

## Sharing and IIIF

A published model can be embedded in another page from `/discover/models/{instantiation}/embed`, with its published hotspots. Its IIIF manifest is at `/iiif/3d/{instantiation}/manifest`, after the IIIF 3D group's release candidate of January 2026: the model, a camera and a light on a canvas with depth, and its hotspots as annotations at a point. That model is provisional until the IIIF 3D specification is final.

## When something does not work

| Problem | What to try |
| --- | --- |
| The model does not load | Reload the page, or try another browser; a very large model can take a while |
| No viewing copy was made | Blender could not read the file; it is still kept, and can be downloaded |
| A hotspot is not shown to the public | Its text is a draft: publish the record |
| No augmented reality button | Turn it on in the model's settings; it needs an Android device with ARCore |

: When a 3D model does not work

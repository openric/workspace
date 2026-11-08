# OpenRiC Workspace - a 3D model's viewing copy and thumbnail made with Blender (roadmap phase 21, 3D objects), after
# Heratio's ahg-3d-model tools/3d-thumbnail/blender_thumbnail.py: the model imported whatever its format (glTF, OBJ,
# STL, PLY, USD), then either written as one binary glTF (GLB) that a browser shows, or rendered from above and in
# front, framed to fit. Changed from Heratio: the importers this Blender has are chosen (they moved between versions),
# and the thumbnail is rendered with Workbench, which needs no GPU on a server.
# Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# blender --background --factory-startup --python model3d.py -- glb   <input> <output.glb>
# blender --background --factory-startup --python model3d.py -- thumb <input> <output.png> [size]
# blender --background --factory-startup --python model3d.py -- turntable <input> <frame-prefix> [size] [frames]
import math
import os
import sys

import bpy
import mathutils


def op(*names):
    """The first of these operators this Blender has."""
    for name in names:
        group, _, fn = name.partition('.')
        candidate = getattr(getattr(bpy.ops, group), fn)
        try:
            candidate.get_rna_type()
            return candidate
        except (AttributeError, KeyError):
            continue
    raise ValueError('none of ' + ', '.join(names) + ' is in this Blender')


def import_model(path):
    ext = os.path.splitext(path)[1].lower()
    if ext in ('.glb', '.gltf'):
        op('import_scene.gltf')(filepath=path)
    elif ext == '.obj':
        op('wm.obj_import', 'import_scene.obj')(filepath=path)
    elif ext == '.stl':
        op('wm.stl_import', 'import_mesh.stl')(filepath=path)
    elif ext == '.ply':
        op('wm.ply_import', 'import_mesh.ply')(filepath=path)
    elif ext in ('.usd', '.usda', '.usdc', '.usdz'):
        op('wm.usd_import')(filepath=path)
    else:
        raise ValueError('not a 3D format this reads: ' + ext)
    meshes = [o for o in bpy.data.objects if o.type == 'MESH']
    if not meshes:
        raise ValueError('the file holds no mesh')
    return meshes


def bounds(objects):
    lo, hi = [math.inf] * 3, [-math.inf] * 3
    for obj in objects:
        for corner in obj.bound_box:
            w = obj.matrix_world @ mathutils.Vector(corner)
            for i in range(3):
                lo[i], hi[i] = min(lo[i], w[i]), max(hi[i], w[i])
    return lo, hi


def stage(objects, size):
    """A camera framing the model, Workbench rendering, and the model's centre and extent."""
    scene = bpy.context.scene
    lo, hi = bounds(objects)
    centre = mathutils.Vector([(lo[i] + hi[i]) / 2 for i in range(3)])
    extent = max(hi[i] - lo[i] for i in range(3)) or 1.0
    bpy.ops.object.camera_add()
    scene.camera = bpy.context.object
    scene.render.engine = 'BLENDER_WORKBENCH'
    scene.display.shading.light = 'STUDIO'
    scene.display.shading.color_type = 'TEXTURE'
    scene.display.shading.show_shadows = True
    scene.render.resolution_x = scene.render.resolution_y = size
    scene.render.resolution_percentage = 100
    scene.render.film_transparent = False
    scene.render.image_settings.file_format = 'PNG'
    return scene, centre, extent


def aim(camera, centre, extent, angle):
    distance = extent * 2.3
    camera.location = centre + mathutils.Vector((distance * math.cos(angle), distance * math.sin(angle), extent * 1.1))
    camera.rotation_euler = (centre - camera.location).to_track_quat('-Z', 'Y').to_euler()


def thumbnail(objects, output, size):
    scene, centre, extent = stage(objects, size)
    aim(scene.camera, centre, extent, math.radians(-45))
    scene.render.filepath = output
    bpy.ops.render.render(write_still=True)


def turntable(objects, prefix, size, frames):
    """Frames all round the model, as Heratio's render_multiangle.py renders them, for FFmpeg to join."""
    scene, centre, extent = stage(objects, size)
    for i in range(frames):
        aim(scene.camera, centre, extent, math.radians(-45) + 2 * math.pi * i / frames)
        scene.render.filepath = '%s%04d.png' % (prefix, i + 1)
        bpy.ops.render.render(write_still=True)


def main():
    argv = sys.argv[sys.argv.index('--') + 1:]
    if len(argv) < 3 or argv[0] not in ('glb', 'thumb', 'turntable'):
        print('usage: model3d.py -- glb|thumb|turntable <input> <output> [size] [frames]')
        sys.exit(2)
    mode, source, output = argv[0], argv[1], argv[2]
    bpy.ops.wm.read_factory_settings(use_empty=True)
    objects = import_model(source)
    size = int(argv[3]) if len(argv) > 3 else 512
    if mode == 'glb':
        bpy.ops.export_scene.gltf(filepath=output, export_format='GLB')
    elif mode == 'thumb':
        thumbnail(objects, output, size)
    else:
        turntable(objects, output, size, int(argv[4]) if len(argv) > 4 else 72)
        return
    if not os.path.isfile(output) or os.path.getsize(output) == 0:
        print('nothing was written')
        sys.exit(1)


if __name__ == '__main__':
    main()

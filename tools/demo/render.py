#!/usr/bin/env python3
"""Render the checked-in README demo and social preview.

The renderer is intentionally separate from the PHP package toolchain. Install
Pillow in a disposable environment, then run:

    python -m pip install -r tools/demo/requirements.txt
    python tools/demo/render.py
"""

from __future__ import annotations

from pathlib import Path

from PIL import Image, ImageDraw, ImageFont


ROOT = Path(__file__).resolve().parents[2]
ASSETS = ROOT / "docs" / "assets"
WIDTH = 1280
HEIGHT = 720

BACKGROUND = "#07110f"
PANEL = "#0d1b18"
PANEL_LIGHT = "#122721"
GRID = "#17332d"
TEXT = "#f2fbf7"
MUTED = "#91aaa2"
EMERALD = "#39e6aa"
EMERALD_DARK = "#0f6f55"
AMBER = "#ffb454"
RED = "#ff6b6b"


def font(size: int, bold: bool = False) -> ImageFont.FreeTypeFont | ImageFont.ImageFont:
    names = (
        ["C:/Windows/Fonts/seguisb.ttf", "C:/Windows/Fonts/arialbd.ttf"]
        if bold
        else ["C:/Windows/Fonts/segoeui.ttf", "C:/Windows/Fonts/arial.ttf"]
    )
    names += [
        "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"
        if bold
        else "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf"
    ]

    for name in names:
        if Path(name).is_file():
            return ImageFont.truetype(name, size)

    return ImageFont.load_default()


FONTS = {
    "eyebrow": font(22, True),
    "title": font(46, True),
    "subtitle": font(25),
    "participant": font(22, True),
    "small": font(19),
    "metric": font(31, True),
    "status": font(26, True),
    "social_title": font(66, True),
    "social_subtitle": font(29),
}


def rounded(
    draw: ImageDraw.ImageDraw,
    box: tuple[int, int, int, int],
    fill: str,
    outline: str | None = None,
    radius: int = 18,
    width: int = 2,
) -> None:
    draw.rounded_rectangle(box, radius=radius, fill=fill, outline=outline, width=width)


def base_frame(kicker: str, title: str, subtitle: str) -> tuple[Image.Image, ImageDraw.ImageDraw]:
    image = Image.new("RGB", (WIDTH, HEIGHT), BACKGROUND)
    draw = ImageDraw.Draw(image)

    for x in range(0, WIDTH, 64):
        draw.line((x, 0, x, HEIGHT), fill=GRID, width=1)
    for y in range(0, HEIGHT, 64):
        draw.line((0, y, WIDTH, y), fill=GRID, width=1)

    draw.rectangle((0, 0, WIDTH, HEIGHT), fill=(3, 10, 9, 120))
    draw.text((64, 48), kicker.upper(), font=FONTS["eyebrow"], fill=EMERALD)
    draw.text((64, 82), title, font=FONTS["title"], fill=TEXT)
    draw.text((64, 144), subtitle, font=FONTS["subtitle"], fill=MUTED)
    draw.line((64, 190, 1216, 190), fill=GRID, width=2)

    return image, draw


def participant_row(
    draw: ImageDraw.ImageDraw,
    y: int,
    participant: str,
    checkpoint: str,
    status: str,
    color: str,
) -> None:
    rounded(draw, (64, y, 820, y + 86), PANEL, GRID, 16)
    rounded(draw, (84, y + 18, 136, y + 70), color, None, 14)
    draw.text((101, y + 27), participant[-1], font=FONTS["participant"], fill=BACKGROUND)
    draw.text((158, y + 17), participant, font=FONTS["participant"], fill=TEXT)
    draw.text((158, y + 49), checkpoint, font=FONTS["small"], fill=MUTED)
    draw.line((430, y + 43, 636, y + 43), fill=color, width=4)
    rounded(draw, (654, y + 19, 798, y + 67), PANEL_LIGHT, color, 13)
    width = draw.textlength(status, font=FONTS["small"])
    draw.text((726 - width / 2, y + 31), status, font=FONTS["small"], fill=color)


def metrics(
    draw: ImageDraw.ImageDraw,
    values: list[tuple[str, str, str]],
    heading: str,
) -> None:
    rounded(draw, (852, 224, 1216, 568), PANEL, GRID, 18)
    draw.text((884, 252), heading, font=FONTS["participant"], fill=TEXT)

    y = 308
    for label, value, color in values:
        draw.text((884, y), label, font=FONTS["small"], fill=MUTED)
        draw.text((884, y + 27), value, font=FONTS["metric"], fill=color)
        y += 82


def footer(draw: ImageDraw.ImageDraw, label: str, color: str) -> None:
    rounded(draw, (64, 620, 1216, 674), PANEL_LIGHT, color, 15)
    draw.ellipse((86, 638, 102, 654), fill=color)
    draw.text((118, 633), label, font=FONTS["status"], fill=TEXT)
    draw.text((1015, 637), "raceproof/laravel", font=FONTS["small"], fill=MUTED)


def intro_frame() -> Image.Image:
    image, draw = base_frame(
        "Controlled concurrency testing",
        "Make the race arrive on cue.",
        "Three real Laravel processes. One shared database. Explicit checkpoints.",
    )
    rounded(draw, (64, 248, 1216, 560), PANEL, GRID, 24)

    for index, y in enumerate((310, 390, 470), start=1):
        draw.ellipse((120, y - 17, 154, y + 17), fill=EMERALD)
        draw.text((132, y - 13), str(index), font=FONTS["small"], fill=BACKGROUND)
        draw.line((172, y, 548, y), fill=EMERALD_DARK, width=5)
        draw.ellipse((542, y - 9, 560, y + 9), fill=EMERALD)

    draw.rectangle((620, 282, 650, 512), fill=EMERALD_DARK)
    draw.text((698, 314), "Start together", font=FONTS["status"], fill=TEXT)
    draw.text((698, 362), "wait at race_point()", font=FONTS["status"], fill=TEXT)
    draw.text((698, 410), "release as one cohort", font=FONTS["status"], fill=TEXT)
    draw.text((698, 478), "Then assert responses and invariants.", font=FONTS["small"], fill=MUTED)
    footer(draw, "A deterministic test harness, not a load tester", EMERALD)

    return image


def waiting_frame(checkpoint: str, fixed: bool) -> Image.Image:
    title = "Fixed path: atomic stock claim" if fixed else "Broken path: stale read, then write"
    image, draw = base_frame(
        "Executable overselling fixture",
        title,
        "Every participant is paused inside application code before release.",
    )
    for index, y in enumerate((230, 332, 434), start=1):
        participant_row(draw, y, f"participant-{index}", checkpoint, "waiting", EMERALD)

    metrics(
        draw,
        [
            ("Checkpoint", "3 / 3", EMERALD),
            ("Shared stock", "1", TEXT),
            ("Requests", "3", TEXT),
        ],
        "Barrier state",
    )
    footer(draw, f"All participants reached race_point('{checkpoint}')", EMERALD)

    return image


def outcome_frame(fixed: bool) -> Image.Image:
    if fixed:
        image, draw = base_frame(
            "Executable overselling fixture",
            "The invariant survives the race.",
            "The atomic decrement lets exactly one request claim the final unit.",
        )
        statuses = [("201", EMERALD), ("409", AMBER), ("409", AMBER)]
        values = [
            ("Orders created", "1", EMERALD),
            ("Final stock", "0", EMERALD),
            ("Server errors", "0", EMERALD),
        ]
        footer_label = "PASS  -  one 201, two 409 responses, invariant holds"
        footer_color = EMERALD
    else:
        image, draw = base_frame(
            "Executable overselling fixture",
            "The broken implementation fails on demand.",
            "All three requests observed stock 1 before any of them decremented it.",
        )
        statuses = [("201", RED), ("201", RED), ("201", RED)]
        values = [
            ("Orders created", "3", RED),
            ("Final stock", "-2", RED),
            ("Server errors", "0", EMERALD),
        ]
        footer_label = "REPRODUCED  -  overselling invariant violated"
        footer_color = RED

    checkpoint = "atomic claim complete" if fixed else "stale update complete"
    for index, (status, color) in enumerate(statuses, start=1):
        participant_row(draw, 230 + ((index - 1) * 102), f"participant-{index}", checkpoint, status, color)

    metrics(draw, values, "Observed result")
    footer(draw, footer_label, footer_color)

    return image


def transition_frame() -> Image.Image:
    image, draw = base_frame(
        "Regression",
        "Replace the read/write gap with one atomic claim.",
        "The test stays the same; only the application behavior changes.",
    )
    rounded(draw, (64, 252, 1216, 550), PANEL, GRID, 24)
    draw.text((114, 300), "stale read", font=FONTS["status"], fill=RED)
    draw.line((276, 319, 510, 319), fill=RED, width=5)
    draw.text((114, 370), "separate decrement", font=FONTS["status"], fill=RED)
    draw.line((354, 389, 510, 389), fill=RED, width=5)
    draw.text((582, 330), "->", font=FONTS["title"], fill=MUTED)
    rounded(draw, (718, 292, 1128, 458), PANEL_LIGHT, EMERALD, 20)
    draw.text((773, 326), "WHERE stock > 0", font=FONTS["status"], fill=EMERALD)
    draw.text((773, 378), "DECREMENT stock", font=FONTS["status"], fill=TEXT)
    footer(draw, "Same schedule. Fixed critical section. Permanent regression test.", EMERALD)

    return image


def render_demo() -> None:
    frames = [
        intro_frame(),
        waiting_frame("oversell-read", fixed=False),
        outcome_frame(fixed=False),
        transition_frame(),
        waiting_frame("oversell-claim", fixed=True),
        outcome_frame(fixed=True),
    ]
    durations = [1600, 1500, 2300, 1400, 1500, 2800]
    frames[0].save(
        ASSETS / "raceproof-demo.gif",
        save_all=True,
        append_images=frames[1:],
        duration=durations,
        loop=0,
        optimize=True,
        disposal=2,
    )


def render_social_preview() -> None:
    hero = Image.open(ASSETS / "raceproof-hero.png").convert("RGB")
    source_ratio = hero.width / hero.height
    target_ratio = 2.0

    if source_ratio > target_ratio:
        crop_width = int(hero.height * target_ratio)
        left = (hero.width - crop_width) // 2
        hero = hero.crop((left, 0, left + crop_width, hero.height))
    else:
        crop_height = int(hero.width / target_ratio)
        top = (hero.height - crop_height) // 2
        hero = hero.crop((0, top, hero.width, top + crop_height))

    hero = hero.resize((1280, 640), Image.Resampling.LANCZOS)
    overlay = Image.new("RGBA", hero.size, (0, 0, 0, 0))
    overlay_draw = ImageDraw.Draw(overlay)
    for x in range(860):
        alpha = int(230 * (1 - (x / 860)))
        overlay_draw.line((x, 0, x, 640), fill=(3, 10, 9, alpha))

    composed = Image.alpha_composite(hero.convert("RGBA"), overlay)
    draw = ImageDraw.Draw(composed)
    draw.text((74, 202), "RaceProof", font=FONTS["social_title"], fill=TEXT)
    draw.text((74, 282), "for Laravel", font=FONTS["social_title"], fill=EMERALD)
    draw.text(
        (78, 382),
        "Controlled, reproducible concurrency tests.",
        font=FONTS["social_subtitle"],
        fill=TEXT,
    )
    draw.text(
        (78, 430),
        "Coordinate real processes. Assert the invariant.",
        font=FONTS["small"],
        fill=MUTED,
    )
    composed.convert("RGB").save(
        ASSETS / "raceproof-social-preview.png",
        format="PNG",
        optimize=True,
    )


if __name__ == "__main__":
    ASSETS.mkdir(parents=True, exist_ok=True)
    render_demo()
    render_social_preview()

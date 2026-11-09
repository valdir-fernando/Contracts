"""Private documentary extraction; emits cells, never writes to the database."""

import json
import hashlib
import pathlib
import subprocess
import sys
import tempfile
import concurrent.futures
import os

import pdfplumber
import numpy as np
import cv2
from PIL import Image


def run(args):
    subprocess.run(
        args, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=180
    )


def centers(values):
    groups = []
    for value in values:
        if not groups or value > groups[-1][-1] + 1:
            groups.append([])
        groups[-1].append(value)
    return [sum(group) / len(group) for group in groups]


def raster_rows(page, image_path, roles):
    image = Image.open(image_path).convert("L")
    gray = np.asarray(image)
    height, width = gray.shape
    binary = cv2.adaptiveThreshold(
        gray, 255, cv2.ADAPTIVE_THRESH_MEAN_C, cv2.THRESH_BINARY_INV, 25, 12
    )
    horizontal_mask = cv2.morphologyEx(
        binary, cv2.MORPH_OPEN, cv2.getStructuringElement(cv2.MORPH_RECT, (70, 1))
    )
    vertical_mask = cv2.morphologyEx(
        binary, cv2.MORPH_OPEN, cv2.getStructuringElement(cv2.MORPH_RECT, (1, 60))
    )
    contours, _ = cv2.findContours(
        horizontal_mask, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE
    )
    horizontal = sorted(
        y + h / 2 for x, y, w, h in map(cv2.boundingRect, contours) if w > width * 0.55
    )
    contours, _ = cv2.findContours(
        vertical_mask, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE
    )
    vertical_lines = [
        cv2.boundingRect(c) for c in contours if cv2.boundingRect(c)[3] > 60
    ]
    rows = []
    for top, bottom in zip(horizontal, horizontal[1:]):
        if bottom - top < 15:
            continue
        vertical = sorted(
            x + w / 2
            for x, y, w, h in vertical_lines
            if y <= top + 10 and y + h >= bottom - 10
        )
        if len(vertical) < 2:
            continue
        boxes = []
        for left, right in zip(vertical, vertical[1:]):
            if right - left > 10:
                boxes.append(
                    (int(left) + 4, int(top) + 4, int(right) - 4, int(bottom) - 4)
                )
        rows.append(boxes)
    line_mask = cv2.dilate(
        cv2.bitwise_or(horizontal_mask, vertical_mask), np.ones((3, 3), np.uint8)
    )
    cleaned = gray.copy()
    cleaned[line_mask > 0] = 255
    image = Image.fromarray(cleaned)
    cache = image_path.parent / "cells-v2"
    cache.mkdir(exist_ok=True, mode=0o700)

    def recognize(pair):
        box, numeric = pair
        key = (
            image_path.stem
            + "-"
            + hashlib.sha256((str(box) + str(numeric)).encode()).hexdigest()
        )
        target = cache / (key + ".txt")
        if target.exists():
            return target.read_text()
        crop = cache / (key + ".png")
        image.crop(box).save(crop)
        arguments = [
            "tesseract",
            str(crop),
            "stdout",
            "-l",
            "por",
            "--psm",
            "7" if numeric else "6",
        ]
        if numeric:
            arguments += ["-c", "tessedit_char_whitelist=0123456789.,"]
        result = subprocess.run(
            arguments,
            check=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            timeout=60,
            env={**os.environ, "OMP_THREAD_LIMIT": "1"},
        )
        text = result.stdout.decode().strip()
        target.write_text(text)
        return text

    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        result = []
        for boxes in rows:
            cells = list(pool.map(recognize, [(box, False) for box in boxes]))
            labels = [
                "".join(c for c in value.upper() if c.isalpha()) for value in cells
            ]
            if any(
                value.startswith("QTD") or value.startswith("QUANT") for value in labels
            ) and any("UNIT" in value for value in labels):
                roles["numeric"] = [
                    i
                    for i, value in enumerate(labels)
                    if value in ["ID", "ITEM"]
                    or value.startswith("QTD")
                    or value.startswith("QUANT")
                ]
                result.append(cells)
            else:
                for index, value in enumerate(
                    pool.map(
                        recognize,
                        [
                            (box, True)
                            for i, box in enumerate(boxes)
                            if i in roles.get("numeric", [])
                        ],
                    )
                ):
                    cells[
                        [i for i in range(len(boxes)) if i in roles.get("numeric", [])][
                            index
                        ]
                    ] = value
                result.append(cells)
    return result


def deskew(path):
    image = Image.open(path).convert("L")
    small = image.copy()
    small.thumbnail((800, 1200))
    best = (0, 0)
    for angle in np.arange(-2, 2.01, 0.1):
        projection = (np.asarray(small.rotate(float(angle), fillcolor=255)) < 150).sum(
            axis=1
        )
        score = np.sort(projection)[-40:].astype(float) @ np.sort(projection)[
            -40:
        ].astype(float)
        if score > best[1]:
            best = (float(angle), score)
    image.rotate(best[0], fillcolor=255).save(path)


def extract(path, ocr=False):
    pages = []
    with tempfile.TemporaryDirectory(prefix="ata-products-") as temporary:
        pdf_path = path
        images = []
        if ocr:
            cache = (
                pathlib.Path("storage/app/private/ata-product-ocr")
                / hashlib.sha256(path.read_bytes()).hexdigest()
            )
            cache.mkdir(parents=True, exist_ok=True, mode=0o700)
            pdf_path = str(cache / "recognized.pdf")
            if not pathlib.Path(pdf_path).exists():
                run(
                    [
                        "pdftoppm",
                        "-scale-to",
                        "3300",
                        "-png",
                        str(path),
                        str(cache / "page"),
                    ]
                )
                images = sorted(cache.glob("page-*.png"))
                recognized = []
                for index, image in enumerate(images):
                    deskew(image)
                    output = str(cache / ("ocr-" + str(index)))
                    run(
                        [
                            "tesseract",
                            str(image),
                            output,
                            "-l",
                            "por",
                            "--psm",
                            "3",
                            "pdf",
                        ]
                    )
                    recognized.append(output + ".pdf")
                run(["pdfunite", *recognized, pdf_path])
            images = sorted(cache.glob("page-*.png"))
        with pdfplumber.open(pdf_path) as pdf:
            roles = {}
            for index, page in enumerate(pdf.pages):
                tables = (
                    [raster_rows(page, images[index], roles)]
                    if ocr
                    else page.extract_tables()
                )
                pages.append(
                    {
                        "page": index + 1,
                        "text": page.extract_text() or "",
                        "tables": tables,
                    }
                )
    return {"pages": pages, "extraction": "ocr_grid_por" if ocr else "pdf_grid"}


if __name__ == "__main__":
    print(
        json.dumps(
            extract(pathlib.Path(sys.argv[1]), "--ocr" in sys.argv[2:]),
            ensure_ascii=False,
        )
    )

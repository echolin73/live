import os
import subprocess

STREAM_FILE = "stream.txt"
OUTPUT_DIR = "channels"
PLAYLIST = "ytplaylist.m3u"

os.makedirs(OUTPUT_DIR, exist_ok=True)

def get_stream_url(url):
    try:
        result = subprocess.run(
            ["yt-dlp", "-g", url],
            capture_output=True,
            text=True
        )
        return result.stdout.strip()
    except:
        return None


def parse_line(line):
    if "," in line:
        name, url = line.split(",", 1)
        return name.strip(), url.strip()

    url = line.strip()

    if "@" in url:
        name = url.split("@")[1].split("/")[0]
    else:
        name = "channel"

    return name, url


playlist = ["#EXTM3U"]

with open(STREAM_FILE, "r", encoding="utf-8") as f:
    lines = f.readlines()

for line in lines:

    if not line.strip():
        continue

    name, url = parse_line(line)

    print("Processing:", name)

    stream_url = get_stream_url(url)

    if not stream_url:
        print("Failed:", name)
        continue

    m3u8_file = f"{OUTPUT_DIR}/{name}.m3u8"

    with open(m3u8_file, "w") as f:
        f.write("#EXTM3U\n")
        f.write(f"#EXTINF:-1,{name}\n")
        f.write(stream_url + "\n")

    playlist.append(f"#EXTINF:-1,{name}")
    playlist.append(m3u8_file)

with open(PLAYLIST, "w") as f:
    f.write("\n".join(playlist))

print("Done")
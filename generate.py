import yt_dlp
import time
import random
import re
import os
from datetime import datetime, timezone

class YouTubePlaylistGenerator:
    def __init__(self):
        self.channels_dir = "channels"
        os.makedirs(self.channels_dir, exist_ok=True)

    def safe_filename(self, name):
        name = re.sub(r'[^\w\s-]', '', name)
        return re.sub(r'[\s-]+', '_', name).lower()

    # ==============================
    # 🔥 核心：高稳定抓流函数
    # ==============================
    def extract_with_fallback(self, url):
        clients = [
            ['android'],
            ['ios'],
            ['web']
        ]

        for client in clients:
            try:
                ydl_opts = {
                    'quiet': True,
                    'no_warnings': True,
                    'retries': 3,
                    'socket_timeout': 30,

                    # ✅ cookies
                    'cookiefile': 'cookies.txt',

                    # ✅ 关键：多客户端
                    'extractor_args': {
                        'youtube': {
                            'player_client': client,
                            'live_from_start': True,
                        }
                    },

                    # ✅ 抗封锁
                    'geo_bypass': True,

                    'http_headers': {
                        'User-Agent': 'Mozilla/5.0 (Linux; Android 10)',
                        'Accept-Language': 'en-US,en;q=0.9',
                    }
                }

                with yt_dlp.YoutubeDL(ydl_opts) as ydl:
                    info = ydl.extract_info(url, download=False)

                if info and info.get("formats"):
                    return info

            except Exception as e:
                print(f"   ⚠️ client {client} failed")

        return None

    def get_stream_info(self, url):
        info = self.extract_with_fallback(url)

        if not info:
            return None

        video_id = info.get("id")
        name = info.get("channel") or info.get("uploader") or "Unknown"

        formats = info.get("formats", [])

        video_formats = [
            f for f in formats
            if f.get("url") and f.get("vcodec") != "none"
        ]

        if not video_formats:
            return {
                "status": "offline",
                "name": name,
                "video_id": video_id
            }

        # 🔥 按清晰度排序
        video_formats.sort(
            key=lambda f: (f.get("height", 0), f.get("tbr", 0)),
            reverse=True
        )

        streams = {}

        # HD
        for f in video_formats:
            if f.get("height", 0) >= 720:
                streams["hd"] = {
                    "url": f["url"],
                    "quality": f"{f.get('height')}p"
                }
                break

        # Mobile
        for f in reversed(video_formats):
            if f.get("height", 0) <= 480:
                streams["mobile"] = {
                    "url": f["url"],
                    "quality": f"{f.get('height')}p"
                }
                break

        # fallback
        if not streams:
            f = video_formats[0]
            streams["main"] = {
                "url": f["url"],
                "quality": f"{f.get('height')}p"
            }

        return {
            "status": "live",
            "name": name,
            "video_id": video_id,
            "streams": streams
        }

    # ==============================
    # 🎬 生成主播放列表
    # ==============================
    def generate_playlists(self, channels):
        header = [
            "#EXTM3U",
            f"# Generated: {datetime.now(timezone.utc)}",
            ""
        ]

        playlists = {
            "streams.m3u8": header.copy(),
            "streams_hd.m3u8": header.copy(),
            "streams_mobile.m3u8": header.copy()
        }

        for ch in channels:
            if ch["status"] != "live":
                continue

            name = ch["name"]

            main = ch["streams"].get("hd") or next(iter(ch["streams"].values()))

            playlists["streams.m3u8"] += [
                f'#EXTINF:-1,{name} [{main["quality"]}]',
                main["url"],
                ""
            ]

            if "hd" in ch["streams"]:
                playlists["streams_hd.m3u8"] += [
                    f'#EXTINF:-1,{name} [HD]',
                    ch["streams"]["hd"]["url"],
                    ""
                ]

            if "mobile" in ch["streams"]:
                playlists["streams_mobile.m3u8"] += [
                    f'#EXTINF:-1,{name} [Mobile]',
                    ch["streams"]["mobile"]["url"],
                    ""
                ]

        for file, content in playlists.items():
            with open(file, "w", encoding="utf-8") as f:
                f.write("\n".join(content))

        print("✅ Playlists generated")

    # ==============================
    # 📺 单频道文件
    # ==============================
    def generate_individual(self, channels):
        for ch in channels:
            if ch["status"] != "live":
                continue

            name = ch["name"]
            safe = self.safe_filename(name)

            stream = ch["streams"].get("hd") or next(iter(ch["streams"].values()))

            with open(f"{self.channels_dir}/{safe}.m3u8", "w") as f:
                f.write("#EXTM3U\n")
                f.write(f'#EXTINF:-1,{name} 🔴\n')
                f.write(stream["url"])

        print("✅ Individual channels done")


def main():
    if not os.path.exists("streams.txt"):
        print("❌ streams.txt missing")
        return

    with open("streams.txt") as f:
        urls = [x.strip() for x in f if x.strip()]

    gen = YouTubePlaylistGenerator()
    channels = []

    for i, url in enumerate(urls, 1):
        print(f"\n[{i}/{len(urls)}] {url}")
        time.sleep(random.uniform(1, 3))

        data = gen.get_stream_info(url)
        if data:
            channels.append(data)
            print(f"   ✅ OK ({len(data['streams'])} streams)")
        else:
            print("   ❌ failed")

    gen.generate_playlists(channels)
    gen.generate_individual(channels)

    print("\n🎉 DONE")


if __name__ == "__main__":
    main()

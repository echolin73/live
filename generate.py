import yt_dlp
import time
import random
import re
import os
import json
from datetime import datetime, timezone, timedelta

QUALITY_PROFILES = {
    'hd': {'min_height': 720, 'suffix': '[HD]'},
    'mobile': {'max_height': 480, 'suffix': '[Mobile]'}
}


class YouTubePlaylistGenerator:

    def __init__(self):
        self.cache_file = '.channel_cache.json'
        self.channels_dir = 'channels'

        if not os.path.exists(self.channels_dir):
            os.makedirs(self.channels_dir)

        self.load_cache()

    def load_cache(self):
        try:
            with open(self.cache_file, 'r') as f:
                self.cache = json.load(f)
        except:
            self.cache = {'channels': {}}

    def save_cache(self):
        with open(self.cache_file, 'w') as f:
            json.dump(self.cache, f)

    def safe_filename(self, name):
        safe = re.sub(r'[^\w\s-]', '', name).strip()
        safe = re.sub(r'[-\s]+', '_', safe)
        return safe.lower()

    def get_stream_info(self, url):

        ydl_opts = {
            'quiet': True,
            'no_warnings': True,
            'socket_timeout': 30,
            'retries': 5
        }

        try:
            with yt_dlp.YoutubeDL(ydl_opts) as ydl:
                info = ydl.extract_info(url, download=False)

            if not info:
                return None

            video_id = info.get('id')
            channel_id = info.get('channel_id', video_id)
            channel_name = info.get('channel', 'Unknown')
            title = info.get('title', '')

            clean_name = re.sub(r'[^\w\s-]', '', channel_name).strip()

            formats = info.get('formats', [])

            if not formats:
                return {
                    'status': 'offline',
                    'name': clean_name,
                    'video_id': video_id,
                    'channel_id': channel_id
                }

            streams = {}

            video_formats = [
                f for f in formats
                if f.get('height') and f.get('url') and f.get('vcodec') != 'none'
            ]

            video_formats.sort(
                key=lambda f: (f.get('height', 0), f.get('fps', 0)),
                reverse=True
            )

            hd = [f for f in video_formats if f.get('height', 0) >= 720]
            mobile = [f for f in video_formats if f.get('height', 0) <= 480]

            if hd:
                streams['hd'] = {
                    'url': hd[0]['url'],
                    'height': hd[0].get('height', 0),
                    'quality_tag': f"{hd[0].get('height', 0)}p"
                }

            if mobile:
                streams['mobile'] = {
                    'url': mobile[0]['url'],
                    'height': mobile[0].get('height', 0),
                    'quality_tag': f"{mobile[0].get('height', 0)}p"
                }

            if not streams and video_formats:
                streams['main'] = {
                    'url': video_formats[0]['url'],
                    'height': video_formats[0].get('height', 0),
                    'quality_tag': f"{video_formats[0].get('height', 0)}p"
                }

            return {
                'status': 'live',
                'name': clean_name,
                'video_id': video_id,
                'channel_id': channel_id,
                'title': title,
                'streams': streams
            }

        except Exception as e:
            print("Error:", str(e)[:120])
            return None

    def generate_playlists(self, channels):

        playlists = {
            'main': [],
            'hd': [],
            'mobile': []
        }

        header = [
            "#EXTM3U",
            f"# Generated: {datetime.now(timezone.utc)}",
            ""
        ]

        for p in playlists:
            playlists[p] = header.copy()

        for channel in channels:

            name = channel['name']
            cid = channel['channel_id']

            if channel['status'] == 'live':

                main_stream = None

                if 'hd' in channel['streams']:
                    main_stream = channel['streams']['hd']
                else:
                    main_stream = list(channel['streams'].values())[0]

                playlists['main'].append(
                    f'#EXTINF:-1 tvg-id="{cid}",{name}'
                )
                playlists['main'].append(main_stream['url'])
                playlists['main'].append("")

                if 'hd' in channel['streams']:
                    s = channel['streams']['hd']
                    playlists['hd'].append(
                        f'#EXTINF:-1 tvg-id="{cid}",{name} [HD]'
                    )
                    playlists['hd'].append(s['url'])
                    playlists['hd'].append("")

                if 'mobile' in channel['streams']:
                    s = channel['streams']['mobile']
                    playlists['mobile'].append(
                        f'#EXTINF:-1 tvg-id="{cid}",{name} [Mobile]'
                    )
                    playlists['mobile'].append(s['url'])
                    playlists['mobile'].append("")

        files = {
            'main': 'streams.m3u8',
            'hd': 'streams_hd.m3u8',
            'mobile': 'streams_mobile.m3u8'
        }

        for p in playlists:
            with open(files[p], 'w', encoding='utf-8') as f:
                f.write("\n".join(playlists[p]))

    def generate_individual(self, channels):

        for ch in channels:

            if ch['status'] != 'live':
                continue

            name = ch['name']
            safe = self.safe_filename(name)

            filename = f"{self.channels_dir}/{safe}.m3u8"

            if 'hd' in ch['streams']:
                stream = ch['streams']['hd']
            else:
                stream = list(ch['streams'].values())[0]

            with open(filename, 'w', encoding='utf-8') as f:

                f.write("#EXTM3U\n")

                f.write(
                    f'#EXTINF:-1 tvg-id="{ch["channel_id"]}",{name}\n'
                )

                f.write(stream['url'] + "\n")

        self.generate_html(channels)

    def generate_html(self, channels):

        cards = ""

        for ch in channels:

            if ch['status'] != 'live':
                continue

            safe = self.safe_filename(ch['name'])

            cards += f"""
<div class="card">
<h3>{ch['name']}</h3>
<a href="{safe}.m3u8">Play</a>
</div>
"""

        html = f"""
<html>
<head>
<title>Channels</title>
<style>
body{{font-family:sans-serif}}
.card{{padding:10px;border:1px solid #ccc;margin:10px}}
</style>
</head>
<body>

<h1>Channels</h1>

{cards}

</body>
</html>
"""

        with open(f"{self.channels_dir}/index.html", "w") as f:
            f.write(html)


def main():

    if not os.path.exists('streams.txt'):
        print("streams.txt missing")
        return

    with open('streams.txt') as f:
        urls = [l.strip() for l in f if l.strip()]

    generator = YouTubePlaylistGenerator()

    channels = []

    for i, url in enumerate(urls, 1):

        print(f"[{i}/{len(urls)}] {url}")

        time.sleep(random.uniform(2, 4))

        info = generator.get_stream_info(url)

        if info:
            channels.append(info)

    generator.generate_playlists(channels)

    generator.generate_individual(channels)

    generator.save_cache()

    print("Done")


if __name__ == "__main__":
    main()
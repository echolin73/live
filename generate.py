import yt_dlp
import time
import random
import re
import os
import json
from datetime import datetime, timezone

# ============= CONFIGURATION =============
QUALITY_PROFILES = {
    'hd': {'min_height': 720, 'suffix': '[HD]'},
    'mobile': {'max_height': 480, 'suffix': '[Mobile]'}
}
# =========================================


class YouTubePlaylistGenerator:
    def __init__(self):
        self.channels_dir = 'channels'

        if not os.path.exists(self.channels_dir):
            os.makedirs(self.channels_dir)

    def safe_filename(self, name):
        safe = re.sub(r'[^\w\s-]', '', name).strip()
        safe = re.sub(r'[-\s]+', '_', safe)
        return safe.lower()

    def get_stream_info(self, url):
        ydl_opts = {
            'quiet': True,
            'no_warnings': True,
            'socket_timeout': 30,
            'retries': 3,
        }

        try:
            with yt_dlp.YoutubeDL(ydl_opts) as ydl:
                info = ydl.extract_info(url, download=False)

                if not info:
                    return None

                video_id = info.get('id')
                channel_id = info.get('channel_id', video_id)
                title = info.get('title', '')
                channel_name = info.get('channel', 'Unknown')

                is_live = info.get('is_live') or info.get('live_status') == 'is_live'

                formats = info.get('formats', [])
                video_formats = [
                    f for f in formats
                    if f.get('height') and f.get('url') and f.get('vcodec') != 'none'
                ]

                if not is_live or not video_formats:
                    return {
                        'status': 'offline',
                        'video_id': video_id,
                        'channel_id': channel_id,
                        'name': channel_name
                    }

                video_formats.sort(key=lambda f: f.get('height', 0), reverse=True)

                streams = {}

                # HD
                hd = [f for f in video_formats if f.get('height', 0) >= 720]
                if hd:
                    streams['hd'] = {
                        'url': hd[0]['url'],
                        'quality': f"{hd[0]['height']}p"
                    }

                # Mobile
                mobile = [f for f in video_formats if f.get('height', 0) <= 480]
                if mobile:
                    streams['mobile'] = {
                        'url': mobile[0]['url'],
                        'quality': f"{mobile[0]['height']}p"
                    }

                if not streams:
                    streams['main'] = {
                        'url': video_formats[0]['url'],
                        'quality': f"{video_formats[0]['height']}p"
                    }

                return {
                    'status': 'live',
                    'video_id': video_id,
                    'channel_id': channel_id,
                    'name': channel_name,
                    'title': title,
                    'streams': streams
                }

        except Exception as e:
            print(f"Error: {str(e)[:100]}")
            return None

    def generate_individual_playlists(self, channels):
        result = []

        for ch in channels:
            if ch.get('status') != 'live':
                continue

            name = ch['name']
            safe = self.safe_filename(name)
            file_path = f"{self.channels_dir}/{safe}.m3u8"

            stream = ch['streams'].get('hd') or next(iter(ch['streams'].values()))

            with open(file_path, 'w', encoding='utf-8') as f:
                f.write("#EXTM3U\n")
                f.write(f'#EXTINF:-1,{name} [{stream["quality"]}] 🔴\n')
                f.write(stream['url'])

            result.append({
                'name': name,
                'file': file_path,
                'quality': stream['quality']
            })

        return result

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

        for k in playlists:
            playlists[k] = header.copy()

        for ch in channels:
            name = ch['name']

            if ch.get('status') != 'live':
                continue

            main_stream = ch['streams'].get('hd') or next(iter(ch['streams'].values()))

            playlists['main'] += [
                f'#EXTINF:-1,{name} [{main_stream["quality"]}]',
                main_stream['url'],
                ""
            ]

            if 'hd' in ch['streams']:
                playlists['hd'] += [
                    f'#EXTINF:-1,{name} [HD]',
                    ch['streams']['hd']['url'],
                    ""
                ]

            if 'mobile' in ch['streams']:
                playlists['mobile'] += [
                    f'#EXTINF:-1,{name} [Mobile]',
                    ch['streams']['mobile']['url'],
                    ""
                ]

        files = {
            'main': 'streams.m3u8',
            'hd': 'streams_hd.m3u8',
            'mobile': 'streams_mobile.m3u8'
        }

        for k, file in files.items():
            with open(file, 'w', encoding='utf-8') as f:
                f.write('\n'.join(playlists[k]))

        return files


def main():
    if not os.path.exists('streams.txt'):
        print("streams.txt not found")
        return

    with open('streams.txt') as f:
        urls = [l.strip() for l in f if l.strip()]

    generator = YouTubePlaylistGenerator()

    channels = []

    for i, url in enumerate(urls, 1):
        print(f"[{i}] {url}")
        time.sleep(random.uniform(1, 3))

        info = generator.get_stream_info(url)
        if info:
            channels.append(info)

    print("Generating playlists...")
    generator.generate_playlists(channels)

    print("Generating individual channels...")
    generator.generate_individual_playlists(channels)

    print("Done ✅")


if __name__ == "__main__":
    main()

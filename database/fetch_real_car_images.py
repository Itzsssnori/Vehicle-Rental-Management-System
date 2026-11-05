import urllib.request
import json
import os
import time
import subprocess

vehicles = [
    (1, "toyota-vios", "Toyota_Vios"),
    (2, "honda-city", "Honda_City"),
    (3, "mitsubishi-montero-sport", "Mitsubishi_Pajero_Sport"),
    (4, "toyota-fortuner", "Toyota_Fortuner"),
    (5, "toyota-innova", "Toyota_Innova"),
    (6, "toyota-rav4", "Toyota_RAV4"),
    (7, "toyota-camry", "Toyota_Camry"),
    (8, "honda-civic", "Honda_Civic"),
    (9, "honda-br-v", "Honda_BR-V"),
    (10, "honda-cr-v", "Honda_CR-V"),
    (11, "honda-accord", "Honda_Accord"),
    (12, "mitsubishi-xpander", "Mitsubishi_Xpander"),
    (13, "mitsubishi-mirage", "Mitsubishi_Mirage"),
    (14, "nissan-almera", "Nissan_Almera"),
    (15, "nissan-terra", "Nissan_Terra"),
    (16, "nissan-navara", "Nissan_Navara"),
    (17, "ford-everest", "Ford_Everest"),
    (18, "ford-ranger", "Ford_Ranger"),
    (19, "mazda-cx-5", "Mazda_CX-5"),
    (20, "hyundai-tucson", "Hyundai_Tucson"),
]

output_dir = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "public", "assets", "vehicles"))
os.makedirs(output_dir, exist_ok=True)

sql_updates = []

headers = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 VRMS/1.0 (info@vrms.local)"
}

print("Fetching real car images from Wikimedia/Wikipedia API...")

for vid, slug, wiki_title in vehicles:
    out_file = os.path.join(output_dir, f"{slug}.jpg")
    web_path = f"/assets/vehicles/{slug}.jpg"
    
    api_url = f"https://en.wikipedia.org/w/api.php?action=query&titles={urllib.parse.quote(wiki_title)}&prop=pageimages&format=json&pithumbsize=900"
    
    try:
        req = urllib.request.Request(api_url, headers=headers)
        with urllib.request.urlopen(req, timeout=10) as resp:
            data = json.loads(resp.read().decode("utf-8"))
        
        pages = data.get("query", {}).get("pages", {})
        first_page = next(iter(pages.values())) if pages else {}
        thumb_url = first_page.get("thumbnail", {}).get("source")
        
        if thumb_url:
            print(f"Downloading {slug} from {thumb_url}...")
            img_req = urllib.request.Request(thumb_url, headers=headers)
            with urllib.request.urlopen(img_req, timeout=15) as img_resp:
                img_data = img_resp.read()
            
            if len(img_data) > 3000:
                with open(out_file, "wb") as f:
                    f.write(img_data)
                sql_updates.append((vid, web_path))
                print(f" [OK] Saved {out_file} ({len(img_data)} bytes)")
            else:
                sql_updates.append((vid, thumb_url))
                print(f" [WARN] Small file for {slug}, using CDN URL")
        else:
            print(f" [ERR] No thumbnail found for {wiki_title}")
    except Exception as e:
        print(f" [FAIL] Error processing {slug}: {e}")
    
    time.sleep(0.8)

print(f"\nDownloaded {len(sql_updates)} vehicle images.")

# Now write a PHP script to apply database updates using PDO
php_script = f"""<?php
require_once __DIR__ . '/../config.php';
$pdo = db();
$updates = {repr(sql_updates)};
$stmt = $pdo->prepare('UPDATE vehicles SET image = ? WHERE id = ?');
foreach ($updates as $item) {{
    $stmt->execute([$item[1], $item[0]]);
}}
echo "Database updated with real vehicle image paths.\n";
"""

with open(os.path.join(os.path.dirname(__file__), "apply-image-updates.php"), "w") as f:
    f.write(php_script)

print("Running database update script...")
subprocess.run(["php", os.path.join(os.path.dirname(__file__), "apply-image-updates.php")], check=True)
print("Done!")

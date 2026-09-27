"""Sdílené pomůcky pro e2e testy: čistá kopie aplikace v dočasné složce, instalace, PHP server, přihlášení."""
import os, re, shutil, sqlite3, subprocess, tempfile, time

import requests

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
USER = "tester"
PASSWORD = "E2E-Test-Heslo-2026!"


class AppUnderTest:
    """Nainstaluje čistou kopii aplikace (prázdná DB) a spustí vestavěný PHP server. Po skončení vše smaže."""

    def __init__(self, port, config_patch=None, php_args=()):
        self.port = port
        self.base = f"http://127.0.0.1:{port}"
        self.admin = self.base + "/admin"
        self._config_patch = config_patch
        self._php_args = list(php_args)
        self.work = tempfile.mkdtemp(prefix="ams-e2e-")
        self.dir = os.path.join(self.work, "app")
        self._proc = None

    def __enter__(self):
        files = subprocess.run(["git", "ls-files", "-co", "--exclude-standard"], cwd=ROOT, capture_output=True, text=True, check=True).stdout.split()
        for rel in files:
            src = os.path.join(ROOT, rel)
            if os.path.isfile(src) and not rel.startswith("storage/") and rel != "config/config.php":
                os.makedirs(os.path.dirname(os.path.join(self.dir, rel)), exist_ok=True)
                shutil.copy2(src, os.path.join(self.dir, rel))
        subprocess.run(["php", "bin/console", "install", f"--base-url={self.base}", "--admin-path=/admin", f"--username={USER}"],
                       cwd=self.dir, env=dict(os.environ, AMS_PASSWORD=PASSWORD), check=True, capture_output=True)
        if self._config_patch:
            path = os.path.join(self.dir, "config", "config.php")
            with open(path) as f:
                content = f.read()
            with open(path, "w") as f:
                f.write(self._config_patch(content))
        self._proc = subprocess.Popen(["php", *self._php_args, "-S", f"127.0.0.1:{self.port}", "-t", "public", "bin/dev-router.php"],
                                      cwd=self.dir, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        for _ in range(50):
            try:
                requests.get(self.base + "/robots.txt", timeout=1, proxies={"http": None, "https": None})
                break
            except requests.ConnectionError:
                time.sleep(0.1)
        return self

    def __exit__(self, *exc):
        if self._proc:
            self._proc.terminate()
            self._proc.wait(timeout=5)
        shutil.rmtree(self.work, ignore_errors=True)

    def db(self, sql, *params):
        with sqlite3.connect(os.path.join(self.dir, "storage", "database.sqlite")) as conn:
            return conn.execute(sql, params).fetchall()

    def console(self, *args):
        return subprocess.run(["php", *self._php_args, "bin/console", *args], cwd=self.dir, capture_output=True, text=True)


def session():
    s = requests.Session()
    s.trust_env = False
    return s


def csrf(html):
    match = re.search(r'name="_csrf" value="([a-f0-9]+)"', html)
    assert match, "formulář bez CSRF tokenu"
    return match.group(1)


def login(app, user=USER, password=PASSWORD):
    s = session()
    r = s.get(app.admin + "/login")
    r = s.post(app.admin + "/login", data={"_csrf": csrf(r.text), "username": user, "password": password}, allow_redirects=False)
    assert r.status_code == 303 and r.headers["Location"].endswith("/admin"), f"přihlášení selhalo: {r.status_code}"
    return s


def flashes(html):
    return re.findall(r'class="flash flash-(\w+)"[^>]*>([^<]*)', html)


class Checker:
    def __init__(self):
        self.results = []

    def __call__(self, ok, name, detail=""):
        self.results.append(bool(ok))
        print(("OK   " if ok else "FAIL ") + name + (f"  [{detail}]" if detail and not ok else ""))

    def summary(self):
        print(f"\n{sum(self.results)}/{len(self.results)} OK")
        return 0 if all(self.results) else 1

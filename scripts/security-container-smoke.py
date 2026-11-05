"""Exercise the real Caddy -> Laravel -> tusd boundary in a disposable CI container."""
import base64
import datetime
import io
import json
import subprocess
import time
import urllib.error
import urllib.request
import zipfile

BASE = "http://127.0.0.1:9998"

def request(method, path, data=None, token=None, headers=None):
    headers = dict(headers or {})
    headers["Accept"] = "application/json"
    if token:
        headers["Authorization"] = "Bearer " + token
    if isinstance(data, dict):
        data = json.dumps(data).encode()
        headers["Content-Type"] = "application/json"
    if path.startswith(BASE):
        path = path[len(BASE):]
    assert path.startswith("/") and not path.startswith("//"), "Unexpected upload URL"
    try:
        with urllib.request.urlopen(urllib.request.Request(BASE + path, data=data, method=method, headers=headers), timeout=15) as response:
            return response.status, dict(response.headers), response.read()
    except urllib.error.HTTPError as error:
        return error.code, dict(error.headers), error.read()


def login(email, password):
    status, headers, body = request("POST", "/api/auth/login", {"email": email, "password": password})
    assert status == 200, (status, body)
    return json.loads(body)["data"]["access_token"]


status, _, body = request("POST", "/api/setup", {
    "email": "owner@example.test", "name": "CI Owner",
    "password": "test-owner-password", "password_confirmation": "test-owner-password",
})
assert status == 200, (status, body)
# Create a second account locally; no SMTP or external identity provider is involved.
subprocess.run(["docker", "exec", "--user", "erugo", "erugo-security", "php", "-r", """
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
App\\Models\\User::create(['name'=>'Other user','email'=>'other@example.test',
    'password'=>'test-other-password','active'=>true,'admin'=>false]);
"""], check=True)
owner = login("owner@example.test", "test-owner-password")
other = login("other@example.test", "test-other-password")
assert request("GET", "/api/settings/smtp_password", token=other)[0] == 403
assert request("POST", "/api/tusd-hooks", {"Type": "post-terminate"})[0] == 404
assert request("POST", "/files/", b"", headers={"Tus-Resumable": "1.0.0", "Upload-Length": "4"})[0] == 401


for extension in [{"Upload-Concat": "partial"}, {"Upload-Defer-Length": "1"}]:
    status, _, body = request("POST", "/files/", b"", owner, {"Tus-Resumable": "1.0.0", **extension})
    assert status == 400, (status, body)


def upload(payload, filename, bundle=False):
    metadata = "filename " + base64.b64encode(filename.encode()).decode()
    if bundle:
        metadata += ",isBundle " + base64.b64encode(b"true").decode()
    status, headers, body = request("POST", "/files/", b"", owner, {
        "Tus-Resumable": "1.0.0", "Upload-Length": str(len(payload)), "Upload-Metadata": metadata,
    })
    assert status == 201, (status, body)
    location = headers["Location"]
    for method in ["HEAD", "PATCH", "DELETE"]:
        h = {"Tus-Resumable": "1.0.0", "Upload-Offset": "0", "Content-Type": "application/offset+octet-stream"}
        assert request(method, location, b"" if method == "PATCH" else None, headers=h)[0] == 401
        assert request(method, location, b"" if method == "PATCH" else None, other, h)[0] == 404
    assert request("GET", location, token=owner)[0] == 405
    assert request("HEAD", location, token=owner, headers={"Tus-Resumable": "1.0.0"})[0] == 200
    status, _, body = request("PATCH", location, payload, owner, {
        "Tus-Resumable": "1.0.0", "Upload-Offset": "0", "Content-Type": "application/offset+octet-stream",
    })
    assert status == 204, (status, body)
    return location.rsplit("/", 1)[1]


upload_id = upload(b"test", "test.txt")
for attempt in range(30):
    status, _, body = request("GET", "/api/uploads/verify/" + upload_id, token=owner)
    if status == 200 and json.loads(body)["data"]["status"] == "complete":
        break
    time.sleep(0.1)
else:
    raise AssertionError((status, body))
assert request("DELETE", "/files/" + upload_id, token=owner, headers={"Tus-Resumable": "1.0.0"})[0] == 204

# Exercise bounded ZIP extraction through tusd's actual post-finish hook.
archive = io.BytesIO()
with zipfile.ZipFile(archive, "w", compression=zipfile.ZIP_DEFLATED) as bundle:
    bundle.writestr("folder/small.txt", "hello")
    bundle.writestr("folder/other.txt", "world")
    bundle.writestr("__erugo_manifest__.json", json.dumps({"files": [
        {"path": "folder/small.txt", "originalName": "small.txt", "size": 0, "type": "text/plain"},
        {"path": "folder/other.txt", "originalName": "other.txt", "size": 0, "type": "text/plain"},
    ]}))
upload_id = upload(archive.getvalue(), "bundle.zip", bundle=True)
# File extraction removes the original tusd file, so inspect only this disposable DB.
subprocess.run(["docker", "exec", "--user", "erugo", "erugo-security", "php", "-r", """
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
for ($attempt=0; $attempt<30; $attempt++) {
    $session = App\\Models\\UploadSession::where('upload_id', $argv[1])->first();
    if ($session && $session->status === 'complete' && $session->is_bundle && (int) $session->filesize === 10) exit(0);
    usleep(100000);
}
exit(1);
""", upload_id], check=True)
status, _, body = request("POST", "/api/uploads/create-share-from-uploads", {
    "upload_id": "local-ci-share", "uploadIds": [upload_id], "name": "CI protected share",
    "description": "Disposable security test", "expiry_date": (datetime.datetime.now(datetime.timezone.utc) + datetime.timedelta(hours=1)).isoformat(),
    "password": "review-share-password", "password_confirm": "review-share-password",
}, owner)
assert status == 200, (status, body)
share_id = json.loads(body)["data"]["share"]["long_id"]
assert request("GET", "/api/shares/" + share_id + "/download/file/folder/small.txt")[0] == 401
for attempt in range(30):
    status, _, body = request("POST", "/api/shares/" + share_id + "/download", {"password": "review-share-password"})
    if status == 200 and zipfile.is_zipfile(io.BytesIO(body)):
        with zipfile.ZipFile(io.BytesIO(body)) as archive:
            assert archive.read("folder/small.txt") == b"hello"
            assert archive.read("folder/other.txt") == b"world"
        break
    time.sleep(0.5)
else:
    raise AssertionError((status, body[:500]))
print("Container security checks passed: settings, private hooks, upload ownership, resume, delete and bounded bundle extraction.")

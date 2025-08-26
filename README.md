# Document Website

Simple Flask application with an admin interface to upload documents, manage user accounts and assign documents to specific users.

## Setup

```bash
pip install -r requirements.txt
python app.py
```

A default admin account is created on first run with username `admin` and password `admin`.

## Usage

1. Navigate to `http://127.0.0.1:5000/login` and log in.
2. Admin users can open `/admin` to create accounts, upload documents, and assign them to users.
3. Logged in users visit `/documents` to download their assigned files.

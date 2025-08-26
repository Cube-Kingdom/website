import os
import sys
import tempfile
import pytest

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), '..')))

from app import app, db, User, Document


@pytest.fixture
def client():
    app.config['SQLALCHEMY_DATABASE_URI'] = 'sqlite:///:memory:'
    app.config['TESTING'] = True
    with app.app_context():
        db.drop_all()
        db.create_all()
        admin = User(username='admin', is_admin=True)
        admin.set_password('admin')
        user = User(username='user', is_admin=False)
        user.set_password('password')
        db.session.add_all([admin, user])
        db.session.commit()
    yield app.test_client()


def login(client, username, password):
    return client.post('/login', data={'username': username, 'password': password}, follow_redirects=True)


def test_user_sees_assigned_docs(client):
    with app.app_context():
        doc1 = Document(filename='doc1.txt', path='doc1.txt')
        doc2 = Document(filename='doc2.txt', path='doc2.txt')
        user = User.query.filter_by(username='user').first()
        user.documents.append(doc1)
        db.session.add_all([doc1, doc2])
        db.session.commit()
    login(client, 'user', 'password')
    rv = client.get('/documents')
    assert b'doc1.txt' in rv.data
    assert b'doc2.txt' not in rv.data

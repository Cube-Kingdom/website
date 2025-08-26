import os
from flask import Flask, render_template, request, redirect, url_for, send_from_directory, flash
from flask_sqlalchemy import SQLAlchemy
from flask_login import LoginManager, login_user, login_required, logout_user, current_user, UserMixin
from werkzeug.security import generate_password_hash, check_password_hash
from werkzeug.utils import secure_filename

basedir = os.path.abspath(os.path.dirname(__file__))
UPLOAD_FOLDER = os.path.join(basedir, 'uploads')
os.makedirs(UPLOAD_FOLDER, exist_ok=True)

app = Flask(__name__)
app.config['SECRET_KEY'] = 'secret-key-change-me'
app.config['SQLALCHEMY_DATABASE_URI'] = 'sqlite:///' + os.path.join(basedir, 'app.db')
app.config['UPLOAD_FOLDER'] = UPLOAD_FOLDER
app.config['MAX_CONTENT_LENGTH'] = 16 * 1024 * 1024

db = SQLAlchemy(app)
login_manager = LoginManager(app)
login_manager.login_view = 'login'

user_documents = db.Table(
    'user_documents',
    db.Column('user_id', db.Integer, db.ForeignKey('user.id'), primary_key=True),
    db.Column('document_id', db.Integer, db.ForeignKey('document.id'), primary_key=True),
)


class User(db.Model, UserMixin):
    id = db.Column(db.Integer, primary_key=True)
    username = db.Column(db.String(150), unique=True, nullable=False)
    password_hash = db.Column(db.String(128), nullable=False)
    is_admin = db.Column(db.Boolean, default=False)
    documents = db.relationship('Document', secondary=user_documents, backref='users')

    def set_password(self, password: str) -> None:
        self.password_hash = generate_password_hash(password)

    def check_password(self, password: str) -> bool:
        return check_password_hash(self.password_hash, password)


class Document(db.Model):
    id = db.Column(db.Integer, primary_key=True)
    filename = db.Column(db.String(255), nullable=False)
    path = db.Column(db.String(255), nullable=False)


@login_manager.user_loader
def load_user(user_id: str):
    return User.query.get(int(user_id))


@app.route('/login', methods=['GET', 'POST'])
def login():
    if request.method == 'POST':
        user = User.query.filter_by(username=request.form['username']).first()
        if user and user.check_password(request.form['password']):
            login_user(user)
            return redirect(url_for('documents'))
        flash('Invalid credentials')
    return render_template('login.html')


@app.route('/logout')
@login_required
def logout():
    logout_user()
    return redirect(url_for('login'))


@app.route('/admin', methods=['GET', 'POST'])
@login_required
def admin():
    if not current_user.is_admin:
        return redirect(url_for('documents'))
    if request.method == 'POST':
        action = request.form.get('action')
        if action == 'create_user':
            username = request.form['username']
            password = request.form['password']
            is_admin = 'is_admin' in request.form
            if User.query.filter_by(username=username).first():
                flash('User exists')
            else:
                user = User(username=username, is_admin=is_admin)
                user.set_password(password)
                db.session.add(user)
                db.session.commit()
                flash('User created')
        elif action == 'upload_document':
            file = request.files['document']
            if file.filename:
                filename = secure_filename(file.filename)
                path = os.path.join(app.config['UPLOAD_FOLDER'], filename)
                file.save(path)
                doc = Document(filename=filename, path=path)
                db.session.add(doc)
                db.session.commit()
                flash('Document uploaded')
        elif action == 'assign_document':
            user_id = request.form['user_id']
            doc_id = request.form['doc_id']
            user = User.query.get(user_id)
            doc = Document.query.get(doc_id)
            if doc not in user.documents:
                user.documents.append(doc)
                db.session.commit()
                flash('Document assigned')
    users = User.query.all()
    docs = Document.query.all()
    return render_template('admin.html', users=users, docs=docs)


@app.route('/documents')
@login_required
def documents():
    if current_user.is_admin:
        docs = Document.query.all()
    else:
        docs = current_user.documents
    return render_template('documents.html', docs=docs)


@app.route('/download/<int:doc_id>')
@login_required
def download(doc_id: int):
    doc = Document.query.get_or_404(doc_id)
    if doc in current_user.documents or current_user.is_admin:
        directory = os.path.dirname(doc.path)
        return send_from_directory(directory, os.path.basename(doc.path), as_attachment=True)
    return redirect(url_for('documents'))


if __name__ == '__main__':
    with app.app_context():
        db.create_all()
        if not User.query.filter_by(is_admin=True).first():
            admin = User(username='admin', is_admin=True)
            admin.set_password('admin')
            db.session.add(admin)
            db.session.commit()
    app.run(debug=True)

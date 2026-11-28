import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import GalanganBrand from '@/components/galangan-brand';
import InputError from '@/components/input-error';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import '../../../css/galangan-public.css';

type Props = { status?: string; canResetPassword: boolean };

export default function Login({ status, canResetPassword }: Props) {
    const [visible, setVisible] = useState(false);
    return (
        <main className="galangan-public login-body">
            <Head title="Masuk" />
            <div className="login-layout">
                <section className="login-main" aria-labelledby="login-title">
                    <GalanganBrand />
                    <div className="login-card">
                        <h1 id="login-title">Login</h1>
                        {status && (
                            <p
                                role="status"
                                className="form-message mt-3"
                                data-success="true"
                            >
                                {status}
                            </p>
                        )}
                        <Form {...store.form()} resetOnSuccess={['password']}>
                            {({ processing, errors }) => (
                                <>
                                    <label htmlFor="email">Email</label>
                                    <input
                                        id="email"
                                        name="email"
                                        autoComplete="email"
                                        type="email"
                                        placeholder="nama@galangan.id"
                                        required
                                    />
                                    <InputError message={errors.email} />
                                    <label htmlFor="password">Kata sandi</label>
                                    <div className="password-wrap">
                                        <input
                                            type={visible ? 'text' : 'password'}
                                            name="password"
                                            id="password"
                                            placeholder="Masukkan kata sandi"
                                            autoComplete="current-password"
                                            required
                                        />
                                        <button
                                            type="button"
                                            aria-controls="password"
                                            aria-pressed={visible}
                                            onClick={() => setVisible(!visible)}
                                        >
                                            {visible ? 'Tutup' : 'Lihat'}
                                        </button>
                                    </div>
                                    <InputError message={errors.password} />
                                    <div className="account-options">
                                        <label>
                                            <input
                                                type="checkbox"
                                                name="remember"
                                                value="1"
                                            />{' '}
                                            Ingat saya
                                        </label>
                                        {canResetPassword && (
                                            <Link
                                                className="back-link"
                                                href={request()}
                                            >
                                                Lupa kata sandi?
                                            </Link>
                                        )}
                                    </div>
                                    <button
                                        className="button button-primary login-submit disabled:opacity-60"
                                        type="submit"
                                        data-test="login-button"
                                        disabled={processing}
                                    >
                                        {processing ? 'Memproses…' : 'Masuk'}
                                    </button>
                                </>
                            )}
                        </Form>
                    </div>
                    <p className="login-footer"></p>
                </section>
                <aside
                    className="login-photo"
                    aria-label="Ilustrasi pembangunan kapal di galangan"
                >
                    <div>
                        <span className="eyebrow">
                            <i /> Monitoring Pembangunan Kapal
                        </span>
                        <p>Setiap tahap pembangunan, tercatat.</p>
                    </div>
                </aside>
            </div>
        </main>
    );
}

import { Link } from '@inertiajs/react';
import { home } from '@/routes';

export default function GalanganBrand() {
    return (
        <Link className="brand" href={home()}>
            <span className="brand-mark" aria-hidden="true">
                ◈
            </span>
            <span>
                Galangan<span className="brand-accent">Monitor</span>
            </span>
        </Link>
    );
}

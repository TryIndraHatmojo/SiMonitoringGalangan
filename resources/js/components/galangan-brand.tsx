import { Link } from '@inertiajs/react';
import { home } from '@/routes';

export default function GalanganBrand() {
    return (
        <Link className="brand" href={home()}>
            <span className="brand-mark" aria-hidden="true">
                ◈
            </span>
            <span>
                Monitoring<span className="brand-accent">Galangan</span>
            </span>
        </Link>
    );
}

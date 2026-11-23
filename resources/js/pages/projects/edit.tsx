import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { workspace } from '@/routes';
import { updateIdentity } from '@/routes/projects';
import type { Ship } from '@/types/monitoring';

const identityFields = [
    { name: 'code', label: 'Kode pembangunan', type: 'text', maxLength: 100 },
    { name: 'name', label: 'Nama kapal', type: 'text', maxLength: 255 },
    { name: 'yard', label: 'Galangan', type: 'text', maxLength: 255 },
    { name: 'start', label: 'Mulai pembangunan', type: 'date' },
    { name: 'end', label: 'Selesai pembangunan', type: 'date' },
] as const;

export default function EditProject({
    project,
}: {
    project: Omit<Ship, 'is_demo'>;
}) {
    const summary = workspace('ringkasan', { query: { project: project.id } });

    return (
        <main className="monitoring min-h-full bg-background px-4 py-8 text-foreground md:px-8">
            <Head title="Edit proyek" />
            <div className="mx-auto grid max-w-6xl gap-4">
                <h1 className="text-3xl font-bold tracking-tight">
                    Edit proyek
                </h1>
                <section>
                    <Form
                        key={project.id}
                        {...updateIdentity.form(project.id)}
                        className="grid items-start gap-4 sm:grid-cols-2"
                    >
                        {({ errors, processing }) => (
                            <>
                                {identityFields.map((field) => {
                                    const { name, label, type } = field;
                                    const error = errors[name];
                                    const errorId = name + '-error';

                                    return (
                                        <div key={name} className="grid gap-1">
                                            <label htmlFor={name}>
                                                {label}
                                            </label>
                                            <Input
                                                id={name}
                                                name={name}
                                                type={type}
                                                defaultValue={project[name]}
                                                maxLength={
                                                    'maxLength' in field
                                                        ? field.maxLength
                                                        : undefined
                                                }
                                                required
                                                aria-invalid={!!error}
                                                aria-describedby={
                                                    error ? errorId : undefined
                                                }
                                            />
                                            <InputError
                                                id={errorId}
                                                message={error}
                                            />
                                        </div>
                                    );
                                })}
                                <div className="flex flex-wrap gap-3 sm:col-span-2">
                                    <Button disabled={processing}>
                                        Simpan perubahan
                                    </Button>
                                    <Button asChild variant="outline">
                                        <Link href={summary}>Batal</Link>
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </section>
            </div>
        </main>
    );
}

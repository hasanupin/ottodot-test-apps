import { FormEvent, ReactNode, useCallback, useEffect, useState } from 'react';
import { ApiError, request } from './api';
import Field, { Option, validate } from './Field';

export type Column<T> = { label: string; render: (row: T) => ReactNode };

export type FieldDef<T> = {
    name: string;
    label: string;
    type?: 'text' | 'email' | 'password' | 'number' | 'datetime-local';
    /** true = always required; 'create' = required only when creating (e.g. password). */
    required?: boolean | 'create';
    min?: number;
    max?: number;
    minLength?: number;
    options?: Option[];
    /** Form value when editing an existing row (passwords: leave undefined so the field starts empty). */
    value?: (row: T) => string;
    /** Number fields are sent as numbers, empty → null. */
    numeric?: boolean;
};

type Props<T extends { id: number }> = {
    title: string;
    /** Singular name for form headings, e.g. "teacher". */
    noun: string;
    endpoint: string;
    columns: Column<T>[];
    fields: FieldDef<T>[];
    canEdit: boolean;
    emptyText: string;
};

type Values = Record<string, string>;
type Errors = Record<string, string | undefined>;

/** A table of rows from `endpoint`; admins also get an inline create/edit form and delete. */
export default function ResourcePage<T extends { id: number }>({ title, noun, endpoint, columns, fields, canEdit, emptyText }: Props<T>) {
    const [rows, setRows] = useState<T[] | null>(null);
    const [alert, setAlert] = useState<string | null>(null);
    const [editing, setEditing] = useState<T | 'new' | null>(null);
    const [values, setValues] = useState<Values>({});
    const [errors, setErrors] = useState<Errors>({});
    const [saving, setSaving] = useState(false);

    const load = useCallback(() => {
        request<T[]>('GET', endpoint)
            .then(setRows)
            .catch((err: Error) => setAlert(err.message));
    }, [endpoint]);

    useEffect(load, [load]);

    const isNew = editing === 'new';
    const labelOf = (name: string) => fields.find((f) => f.name === name)?.label ?? name;
    const isRequired = (f: FieldDef<T>) => f.required === true || (f.required === 'create' && isNew);

    function open(row: T | 'new') {
        setEditing(row);
        setErrors({});
        setAlert(null);
        setValues(Object.fromEntries(fields.map((f) => [f.name, row !== 'new' && f.value ? f.value(row) : ''])));
    }

    function check(control: HTMLInputElement | HTMLSelectElement) {
        setErrors((prev) => ({ ...prev, [control.name]: validate(control, labelOf(control.name)) }));
    }

    async function save(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        setAlert(null);

        const controls = fields.map((f) => e.currentTarget.elements.namedItem(f.name) as HTMLInputElement | HTMLSelectElement);
        const found: Errors = Object.fromEntries(controls.map((c) => [c.name, validate(c, labelOf(c.name))]));
        setErrors(found);
        const firstInvalid = controls.find((c) => found[c.name]);
        if (firstInvalid) {
            firstInvalid.focus();
            return; // invalid → no request
        }

        const body: Record<string, string | number | null> = {};
        for (const f of fields) {
            const raw = values[f.name].trim();
            if (f.type === 'password' && raw === '') continue; // empty password on edit = keep the current one
            body[f.name] = f.numeric ? (raw === '' ? null : Number(raw)) : raw;
        }

        setSaving(true);
        try {
            await request(isNew ? 'POST' : 'PUT', isNew ? endpoint : `${endpoint}/${(editing as T).id}`, body);
            setEditing(null);
            load();
        } catch (err) {
            if (err instanceof ApiError && err.status === 422) {
                setErrors(Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
            } else {
                setAlert(err instanceof Error ? err.message : 'Request failed.');
            }
        } finally {
            setSaving(false);
        }
    }

    async function remove(row: T) {
        if (!window.confirm('Delete this record? This cannot be undone.')) return;
        setAlert(null);
        try {
            await request('DELETE', `${endpoint}/${row.id}`);
            load();
        } catch (err) {
            setAlert(err instanceof Error ? err.message : 'Request failed.'); // e.g. 409 "still has classes"
        }
    }

    return (
        <section className="space-y-4">
            <div className="flex items-center justify-between">
                <h1 className="text-xl font-semibold">{title}</h1>
                {canEdit && editing === null && (
                    <button onClick={() => open('new')} className="rounded bg-black px-3 py-1.5 text-sm text-white">
                        New
                    </button>
                )}
            </div>

            {alert && (
                <p role="alert" className="rounded border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800">
                    {alert}
                </p>
            )}

            {editing !== null && (
                <form onSubmit={save} noValidate className="grid gap-3 rounded border p-4 sm:grid-cols-2">
                    <h2 className="font-medium sm:col-span-2">{isNew ? `New ${noun}` : `Edit ${noun}`}</h2>
                    {fields.map((f) => (
                        <Field
                            key={f.name}
                            label={f.type === 'password' && !isNew ? `${f.label} (leave empty to keep)` : f.label}
                            name={f.name}
                            type={f.type}
                            value={values[f.name] ?? ''}
                            error={errors[f.name]}
                            required={isRequired(f)}
                            min={f.min}
                            max={f.max}
                            minLength={f.minLength}
                            options={f.options}
                            autoComplete={f.type === 'password' ? 'new-password' : undefined}
                            onChange={(c) => {
                                setValues((prev) => ({ ...prev, [c.name]: c.value }));
                                if (errors[c.name]) check(c);
                            }}
                            onBlur={check}
                        />
                    ))}
                    <div className="flex gap-2 sm:col-span-2">
                        <button type="submit" disabled={saving} className="rounded bg-black px-4 py-2 text-white disabled:opacity-50">
                            {saving ? 'Saving…' : 'Save'}
                        </button>
                        <button type="button" onClick={() => setEditing(null)} className="rounded border px-4 py-2">
                            Cancel
                        </button>
                    </div>
                </form>
            )}

            {rows === null ? (
                <p className="text-sm text-gray-600">Loading…</p>
            ) : rows.length === 0 ? (
                <p className="text-sm text-gray-600">{emptyText}</p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b">
                            <tr>
                                {columns.map((c) => (
                                    <th key={c.label} className="py-2 pr-4 font-medium">
                                        {c.label}
                                    </th>
                                ))}
                                {canEdit && <th className="py-2 font-medium">Actions</th>}
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <tr key={row.id} className="border-b last:border-0">
                                    {columns.map((c) => (
                                        <td key={c.label} className="py-2 pr-4">
                                            {c.render(row)}
                                        </td>
                                    ))}
                                    {canEdit && (
                                        <td className="space-x-2 py-2 whitespace-nowrap">
                                            <button onClick={() => open(row)} className="underline">
                                                Edit
                                            </button>
                                            <button onClick={() => remove(row)} className="text-red-700 underline">
                                                Delete
                                            </button>
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}

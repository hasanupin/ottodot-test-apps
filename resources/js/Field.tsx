export type Option = { value: string; label: string };

type FormControl = HTMLInputElement | HTMLSelectElement;

/**
 * Validation message for one control, from the browser's own checks (type, required, min/max, minLength)
 * instead of hand-written regexes. undefined = valid.
 */
export function validate(control: FormControl, label: string): string | undefined {
    const v = control.validity;
    if (v.valueMissing) return `${label} is required.`;
    if (v.typeMismatch) return 'Enter a valid email address.';
    if (v.badInput) return `${label} must be a valid value.`;
    if (v.rangeUnderflow || v.rangeOverflow) {
        const input = control as HTMLInputElement;
        return `${label} must be between ${input.min} and ${input.max || 'any'}.`;
    }
    if (v.tooShort) return `${label} must be at least ${(control as HTMLInputElement).minLength} characters.`;
    return undefined;
}

type FieldProps = {
    label: string;
    name: string;
    type?: 'text' | 'email' | 'password' | 'number' | 'datetime-local';
    value: string;
    error?: string;
    required?: boolean;
    autoComplete?: string;
    min?: number;
    max?: number;
    minLength?: number;
    /** Renders a <select> instead of an <input>. */
    options?: Option[];
    onChange: (control: FormControl) => void;
    onBlur: (control: FormControl) => void;
};

export default function Field({ label, name, type = 'text', value, error, options, onChange, onBlur, ...rest }: FieldProps) {
    const errorId = `${name}-error`;
    const common = {
        name,
        value,
        required: rest.required,
        onChange: (e: { currentTarget: FormControl }) => onChange(e.currentTarget),
        onBlur: (e: { currentTarget: FormControl }) => onBlur(e.currentTarget),
        'aria-invalid': error ? true : undefined,
        'aria-describedby': error ? errorId : undefined,
        className: `mt-1 w-full rounded border px-3 py-2 ${error ? 'border-red-700' : ''}`,
    };

    // The error sits outside the <label> so it isn't read as part of the field's name (aria-describedby links it).
    return (
        <div>
            <label className="block">
                <span className="text-sm">{label}</span>
                {options ? (
                    <select {...common}>
                        <option value="">Select…</option>
                        {options.map((o) => (
                            <option key={o.value} value={o.value}>
                                {o.label}
                            </option>
                        ))}
                    </select>
                ) : (
                    <input
                        {...common}
                        type={type}
                        autoComplete={rest.autoComplete}
                        min={rest.min}
                        max={rest.max}
                        minLength={rest.minLength}
                    />
                )}
            </label>
            {/* Always rendered with a reserved line: an error appearing on blur must not shift the layout,
                or a click on the button below can land on empty space and be lost. */}
            <p id={errorId} className="mt-1 min-h-5 text-sm text-red-700">
                {error}
            </p>
        </div>
    );
}

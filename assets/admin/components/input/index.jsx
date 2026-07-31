import { __experimentalInputControl as InputControl, SelectControl } from '@wordpress/components';
import { useState, useEffect } from 'react';

export const CustomSelectControl = ({ labelName, initialValue, options, onChange }) => {
    const [value, setValue] = useState(initialValue);

    const handleChange = (newValue) => {
        setValue(newValue);
        onChange?.(newValue);
    };

    return (
        <SelectControl
            label={labelName || 'No Label Added'}
            value={value}
            options={options}
            onChange={handleChange}
        />
    );
};

export const InputWithSideLabel = ({ initialValue, labelName, isConfidential, onChange }) => {
    const [value, setValue] = useState(initialValue);
    const type = isConfidential ? 'password' : 'text';

    const handleChange = (nextValue) => {
        const newValue = nextValue ?? '';
        setValue(newValue);
        onChange?.(newValue);
    };

    return (
        <InputControl
            __unstableInputWidth="3em"
            label={labelName || 'Label'}
            value={value}
            type={type}
            labelPosition="edge"
            onChange={handleChange}
        />
    );
};

const Input = ({ initialValue, labelName, onChange, isConfidential, error, isRequired, help }) => {
    const [value, setValue] = useState(initialValue);
    const type = isConfidential ? 'password' : 'text';

    const handleChange = (nextValue) => {
        setValue(nextValue);
        onChange?.(nextValue);
    };

    useEffect(() => {
        // Optional side effects when value changes
    }, [value]);

    const label = isRequired ? `${labelName || 'Label'} *` : (labelName || 'Label');

    return (
        <div style={{ marginBottom: '1rem' }}>
            <InputControl
                label={label}
                value={value}
                type={type}
                required={!!isRequired}
                aria-invalid={!!error}
                onChange={handleChange}
            />
            {error ? (
                <p role="alert" style={{ color: '#d63638', fontSize: '0.875em', marginTop: '0.25rem' }}>
                    {error}
                </p>
            ) : (
                help && (
                    <p style={{ color: '#757575', fontSize: '0.875em', marginTop: '0.25rem' }}>
                        {help}
                    </p>
                )
            )}
        </div>
    );
};

export default Input;
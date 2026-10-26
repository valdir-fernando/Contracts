import test from 'node:test';
import assert from 'node:assert/strict';
import { suggestedContractObject, initializeContractObject } from '../../resources/js/contract-object.js';

const base = { type: '', address: '', facilities: '', role: '', workplace: '', document: '' };

test('property lease uses both address and facilities, preserving the object when either is missing', () => {
    const data = { ...base, type: 'Locação de Imóvel', address: ' Rua A, 10 ', facilities: ' Secretaria de Saúde ' };
    assert.equal(suggestedContractObject(data), 'Locação do imóvel situado no(a) Rua A, 10 destinado as instalações do(a) Secretaria de Saúde.');
    for (const missing of ['address', 'facilities']) assert.equal(suggestedContractObject({ ...data, [missing]: '  ' }), null);
    assert.equal(suggestedContractObject({ ...data, type: 'Fornecimento' }), null);
});

test('credentialing fills for CPF and clears for CNPJ only with role and workplace present', () => {
    const data = { ...base, type: 'Credenciamento', role: ' Médica ', workplace: ' Hospital Municipal ' };
    for (const document of ['52998224725', '529.982.247-25']) {
        assert.equal(suggestedContractObject({ ...data, document }), 'Prestação de Serviços como Médica para trabalhar no(a) Hospital Municipal ...');
    }
    for (const document of ['11222333000181', '11.222.333/0001-81']) assert.equal(suggestedContractObject({ ...data, document }), '');
    for (const missing of ['role', 'workplace']) assert.equal(suggestedContractObject({ ...data, document: '11222333000181', [missing]: '' }), null);
    for (const document of ['', '123', 'CPF52998224725']) assert.equal(suggestedContractObject({ ...data, document }), null);
    assert.equal(suggestedContractObject({ ...data, documentType: 'CPF' }), 'Prestação de Serviços como Médica para trabalhar no(a) Hospital Municipal ...');
    assert.equal(suggestedContractObject({ ...data, documentType: 'CNPJ' }), '');
});

test('each source field change refreshes the object and supplier selection uses the same rule', () => {
    const listeners = {};
    const controls = Object.fromEntries(['tipo', 'objeto', 'end_imovel', 'instalacoes', 'cargo_credenc', 'local_servico', 'fornecedor_documento_snapshot'].map(name => [name, { value: '' }]));
    controls.tipo.addEventListener = (event, callback) => { listeners[event] = callback; };
    for (const name of ['end_imovel', 'instalacoes', 'cargo_credenc', 'local_servico', 'fornecedor_documento_snapshot']) {
        controls[name].addEventListener = (event, callback) => { listeners[`${name}.${event}`] = callback; };
    }
    let emitted;
    controls.objeto.dispatchEvent = (event) => { emitted = event; };
    controls.tipo.value = 'Locação de Imóvel';
    controls.end_imovel.value = 'Rua A'; controls.instalacoes.value = '<Secretaria>';
    controls.objeto.value = 'Descrição anterior';
    initializeContractObject({ elements: { namedItem: (name) => controls[name] }, addEventListener: (event, callback) => { listeners[event] = callback; } });
    assert.equal(controls.objeto.value, 'Descrição anterior');
    listeners.focusout();
    assert.equal(controls.objeto.value, 'Locação do imóvel situado no(a) Rua A destinado as instalações do(a) <Secretaria>.');
    assert.equal(emitted.type, 'input'); assert.equal(emitted.bubbles, true);
    assert.equal(controls.instalacoes.value, '<Secretaria>');
    controls.end_imovel.value = 'Rua B';
    listeners['end_imovel.input']();
    assert.match(controls.objeto.value, /Rua B/);
    controls.instalacoes.value = 'Saúde';
    listeners['instalacoes.change']();
    assert.match(controls.objeto.value, /Saúde/);
    controls.tipo.value = 'Credenciamento';
    controls.cargo_credenc.value = 'Médica'; controls.local_servico.value = 'Hospital';
    controls.fornecedor_documento_snapshot.value = '52998224725';
    listeners.change();
    assert.match(controls.objeto.value, /Médica.*Hospital/);
    controls.cargo_credenc.value = 'Enfermeira'; listeners['cargo_credenc.input']();
    assert.match(controls.objeto.value, /Enfermeira/);
    controls.local_servico.value = 'Clínica'; listeners['local_servico.input']();
    assert.match(controls.objeto.value, /Clínica/);
    controls.fornecedor_documento_snapshot.value = '11222333000181'; listeners['fornecedor_documento_snapshot.input']();
    assert.equal(controls.objeto.value, '');
    controls.fornecedor_documento_snapshot.value = '52998224725'; listeners['supplier-selected']();
    assert.match(controls.objeto.value, /Enfermeira.*Clínica/);
    controls.tipo.value = 'Fornecimento'; controls.objeto.value = 'Manual';
    listeners.focusout(); assert.equal(controls.objeto.value, 'Manual');
});

test('saved credentialing object survives unchanged focusout and document formatting but follows actual source edits', () => {
    const controls = {};
    for (const [name, value] of Object.entries({ version: '3', tipo: 'Credenciamento', objeto: 'Objeto histórico conferido',
        cargo_credenc: 'Médica', local_servico: 'Hospital', fornecedor_documento_snapshot: '52998224725' })) {
        controls[name] = { value, listeners: {}, addEventListener(event, callback) { this.listeners[event] = callback; } };
    }
    const emitted = [];
    controls.objeto.dispatchEvent = event => emitted.push(event);
    initializeContractObject({ elements: { namedItem: name => controls[name] }, addEventListener() {} });
    controls.tipo.listeners.focusout();
    controls.fornecedor_documento_snapshot.value = '529.982.247-25';
    controls.fornecedor_documento_snapshot.listeners.focusout();
    assert.equal(controls.objeto.value, 'Objeto histórico conferido');
    assert.equal(emitted.length, 0);
    controls.cargo_credenc.value = 'Enfermeira';
    controls.cargo_credenc.listeners.input();
    assert.match(controls.objeto.value, /Enfermeira.*Hospital/);
    assert.equal(emitted.length, 1);
    controls.objeto.value = 'Objeto ajustado manualmente';
    controls.cargo_credenc.listeners.focusout();
    assert.equal(controls.objeto.value, 'Objeto ajustado manualmente');
});
